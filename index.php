<?php
require_once __DIR__ . '/src/AmoCrmV4Client.php';

define('SUB_DOMAIN', 'aryunats00');
define('CLIENT_ID', '52d80ccf-dbf1-4ca3-b655-f487e58f40a9');
define('CLIENT_SECRET', 'pMBFN1BoM2zBzL55kxLG6WuI6Y9MxkSM4dg5oBTeb1H9FIbCT1AbNF7fhEsP4pHt');
define('CODE', 'def5020045637fd5aaa88231ffa562677a227351695ff065dc227c85e79d55cf79a868f16192aa270491ce2cd9d5ba4937d0187f77e870614a0313d544162b8f0c0811d5c96785e153a23009e9848a051205d820118067ab16b4406b31d84262c810c3a337bb5aac0e8247c1feb3c211be28347b77e16262c95b9178ad981f2d99265349534a699ef1d691dd1d6179ad71a2c4320a33765489f625fc84c8d85a33ef47053425c960a5d7280ad7833a7f3638cd4caf85066314f9a7e604fcf86a4ec44e3c4c3c3486a0a9e473240f6c50d2f03675365536f9b70f91727c4d8f9ac98e6e1db6e84c1d656a9b895050a414af588aaf1abf8f8da8e6778029482df7ef9f2e39bcf0c27e4a32a1ab9f511a70f1f62ab8711310e5241baf00b23a547aead18bf917158d0f3caaea15889b6c08b5953fd5d0a47c689833f921dc673aab5d10cef8f85655e8840d0ff13d4168278ee38063e50f73f66fc47b8bf102527009e67765d0b0d82d67a914b4839b53384f9aea8b61cfd8ec7ea48561f566f472304189b98f34288920c3a38d251d788eeef29fc510022778a215ce582b6ed894d455a2e3ebade9eb3bfe6a3252bc7d911a20638a6f6ac6bb945b18e88f6c676334878cc51bd1b500c1c60f9988617191a0d8db1e0f39af3903c305e520d6107bccb1edb366ee3cedd10894c322');
define('REDIRECT_URL', 'https://aryunats00.amocrm.ru');

echo "<pre>";
/**
 * Записывает сообщение в лог-файл
 *
 * @param string $message Сообщение для логирования
 * @param string $type Тип сообщения (INFO, ERROR, SUCCESS)
 * @return void
 */
function writeLog($message, $type = 'INFO') {
    $date = date('Y-m-d H:i:s');
    $logMessage = "[$date] [$type] $message" . PHP_EOL;
    file_put_contents("ERROR_LOG.txt", $logMessage, FILE_APPEND);
}

/**
 * Получает ID воронки и статусов по их названиям
 *
 * @param AmoCrmV4Client $amoV4Client Клиент AmoCRM
 * @return array Массив с ID воронок и статусов
 */
function getPipelinesAndStatuses(AmoCrmV4Client $amoV4Client) {
    writeLog("Получение списка воронок и статусов...");

    try {
        // Получаем список всех воронок
        $pipelines = $amoV4Client->GETRequestApi("leads/pipelines");
        if (!isset($pipelines['_embedded']['pipelines'])) {
            writeLog("Ошибка при получении воронок: нет данных в ответе", "ERROR");
            return null;
        }

        $result = [
            'pipelines' => [],
            'statuses' => []
        ];

        // Ищем воронку с названием "Воронка"
        foreach ($pipelines['_embedded']['pipelines'] as $pipeline) {
            $result['pipelines'][$pipeline['name']] = $pipeline['id'];
            $result['statuses'][$pipeline['id']] = [];

            // Сохраняем статусы для каждой воронки
            if (isset($pipeline['_embedded']['statuses'])) {
                foreach ($pipeline['_embedded']['statuses'] as $status) {
                    $result['statuses'][$pipeline['id']][$status['name']] = $status['id'];
                }
            }
        }

        writeLog("Успешно получены данные о воронках и статусах", "SUCCESS");
        return $result;
    } catch (Exception $e) {
        writeLog("Ошибка при получении воронок и статусов: " . $e->getMessage(), "ERROR");
        return null;
    }
}

/**
 * Обновляет статус сделок с бюджетом > 5000 из "Заявка" в "Ожидание клиента"
 *
 * @param AmoCrmV4Client $amoV4Client Клиент AmoCRM
 * @param array $pipelineData Данные о воронках и статусах
 * @return void
 */
function updateLeadsOnBudget(AmoCrmV4Client $amoV4Client, array $pipelineData) {
    writeLog("Запуск функции updateLeadsOnBudget...");

    try {
        // Находим ID воронки "Воронка"
        $pipelineId = $pipelineData['pipelines']['Воронка'] ?? null;
        if (!$pipelineId) {
            writeLog("Воронка с названием 'Воронка' не найдена", "ERROR");
            return;
        }
        // Находим ID статусов
        $applicationStatusId = $pipelineData['statuses'][$pipelineId]['Заявка'] ?? null;
        $waitingClientStatusId = $pipelineData['statuses'][$pipelineId]['Ожидание клиента'] ?? null;

        if (!$applicationStatusId || !$waitingClientStatusId) {
            writeLog("Не найдены необходимые статусы в воронке", "ERROR");
            return;
        }

        writeLog("Получение сделок на этапе 'Заявка' в воронке 'Воронка'...");

        // Получаем сделки на этапе "Заявка"
        $leadsClientApplication = $amoV4Client->GETAll("leads", [
            "filter[statuses][0][pipeline_id]" => $pipelineId,
            "filter[statuses][0][status_id]" => $applicationStatusId
        ]);

        if (empty($leadsClientApplication)) {
            writeLog("Не найдено сделок на этапе 'Заявка'", "INFO");
            return;
        }

        writeLog("Найдено сделок: " . count($leadsClientApplication));
        $updatedCount = 0;

        // Перебираем сделки и обновляем статус если бюджет > 5000
        foreach ($leadsClientApplication as $lead) {
            // Проверка наличия поля price
            if (!isset($lead['price'])) {
                writeLog("Сделка ID: {$lead['id']} не имеет поля 'price', пропускаем", "WARNING");
                continue;
            }

            if ($lead['price'] > 5000) {
                writeLog("Обработка сделки ID: {$lead['id']}, название: '{$lead['name']}', бюджет: {$lead['price']}");
                try {
                    $result = $amoV4Client->POSTRequestApi("leads/{$lead['id']}", [
                        "status_id" => $waitingClientStatusId
                    ], "PATCH");
                    if ($result) {
                        $updatedCount++;
                        writeLog("Сделка ID: {$lead['id']} успешно перемещена на этап 'Ожидание клиента'", "SUCCESS");
                    } else {
                        writeLog("Ошибка при обновлении сделки ID: {$lead['id']}", "ERROR");
                    }
                } catch (Exception $e) {
                    writeLog("Ошибка при обновлении сделки ID: {$lead['id']}: " . $e->getMessage(), "ERROR");
                }
            }
        }
        writeLog("Функция updateLeadsOnBudget завершена. Обновлено сделок: $updatedCount", "SUCCESS");
    } catch (Exception $e) {
        writeLog("Ошибка в функции updateLeadsOnBudget: " . $e->getMessage(), "ERROR");
    }
}

/**
 * Копирует сделки с бюджетом 4999 с этапа "Клиент подтвердил" на этап "Ожидание клиента"
 *
 * @param AmoCrmV4Client $amoV4Client Клиент AmoCRM
 * @param array $pipelineData Данные о воронках и статусах
 * @return void
 */
function copyLeadsOnBudget(AmoCrmV4Client $amoV4Client, array $pipelineData) {
    writeLog("Запуск функции copyLeadsOnBudget...");

    try {
        // Находим ID воронки "Воронка"
        $pipelineId = $pipelineData['pipelines']['Воронка'] ?? null;
        if (!$pipelineId) {
            writeLog("Воронка с названием 'Воронка' не найдена", "ERROR");
            return;
        }

        // Находим ID статусов
        $clientConfirmedStatusId = $pipelineData['statuses'][$pipelineId]['Клиент подтвердил'] ?? null;
        $waitingClientStatusId = $pipelineData['statuses'][$pipelineId]['Ожидание клиента'] ?? null;

        if (!$clientConfirmedStatusId || !$waitingClientStatusId) {
            writeLog("Не найдены необходимые статусы в воронке", "ERROR");
            return;
        }
        writeLog("Получение сделок на этапе 'Клиент подтвердил' с бюджетом 4999...");

        // Получаем сделки на этапе "Клиент подтвердил" с бюджетом 4999
        $leadsClientConfirmation = $amoV4Client->GETAll("leads", [
            "filter[statuses][0][pipeline_id]" => $pipelineId,
            "filter[statuses][0][status_id]" => $clientConfirmedStatusId,
            "filter[price]" => 4999
        ]);

        if (empty($leadsClientConfirmation)) {
            writeLog("Не найдено сделок на этапе 'Клиент подтвердил' с бюджетом 4999", "INFO");
            return;
        }
        writeLog("Найдено сделок для копирования: " . count($leadsClientConfirmation));
        $copiedCount = 0;

        foreach ($leadsClientConfirmation as $lead) {
            writeLog("Обработка сделки ID: {$lead['id']}, название: '{$lead['name']}'");

            try {
                // Получаем все custom fields сделки для полного копирования
                $leadDetails = $amoV4Client->GETRequestApi("leads/{$lead['id']}");

                // Получаем примечания к сделке
                $notes = $amoV4Client->GETRequestApi("leads/{$lead['id']}/notes");
                $notesData = $notes['_embedded']['notes'] ?? [];
                writeLog("Найдено примечаний: " . count($notesData));
                // Получаем задачи сделки
                $tasks = $amoV4Client->GETRequestApi("tasks", [
                    "filter[entity_id]" => $lead['id'],
                    "filter[entity_type]" => "leads"
                ]);
                $tasksData = $tasks['_embedded']['tasks'] ?? [];
                writeLog("Найдено задач: " . count($tasksData));

                // Подготавливаем данные для создания новой сделки
                $newLeadData = [
                    "name" => $lead["name"] . " (копия)",
                    "price" => $lead["price"],
                    "status_id" => $waitingClientStatusId,
                    "pipeline_id" => $pipelineId
                ];

                // Копируем custom fields, если они есть
                if (isset($leadDetails['custom_fields_values'])) {
                    $newLeadData['custom_fields_values'] = $leadDetails['custom_fields_values'];
                }

                // Копируем связанные контакты и компании
                $newLeadData['_embedded'] = [];

                if (isset($lead["_embedded"]["contacts"]) && !empty($lead["_embedded"]["contacts"])) {//проверяем есть ли связанные контакты и НЕ пустые ли они
                    $newLeadData['_embedded']["contacts"] = array_map(function ($contact) {
                        return ['id' => $contact['id']];
                    }, $lead["_embedded"]["contacts"]);
                }

                if (isset($lead["_embedded"]["companies"]) && !empty($lead["_embedded"]["companies"])) {
                    $newLeadData['_embedded']["companies"] = array_map(function ($company) {
                        return ['id' => $company['id']];
                    }, $lead["_embedded"]["companies"]);
                }

                // Создаем новую сделку
                $newLead = $amoV4Client->POSTRequestApi("leads", [$newLeadData]);

                if (!isset($newLead['_embedded']['leads'][0]['id'])) {
                    writeLog("Ошибка при создании копии сделки ID: {$lead['id']}", "ERROR");
                    continue; //переход к следующему циклу
                }

                $newLeadId = $newLead['_embedded']['leads'][0]['id'];
                writeLog("Создана новая сделка с ID: $newLeadId");

                // Копируем примечания
                foreach ($notesData as $note) {
                    if (isset($note['note_type'])) {
                        $noteData = [
                            "note_type" => $note["note_type"]
                        ];
                        switch ($note["note_type"]) {
                            case "common":
                                $noteData["params"] = ["text" => $note["params"]["text"]];
                                break;
                            case "call_in": //входящий звонок
                            case "call_out": //исходящий звонок
                            if (isset($note["params"])) {
                                    $noteData["params"] = $note["params"];
                                }
                                break;
                            case "attachment":  // Примечания с файлом
                                // Проверяем, что параметры для файла присутствуют
                                if (isset($note["params"]["file_uuid"]) && isset($note["params"]["file_name"])) {
                                    $noteData["params"] = [
                                        "text" => $note["params"]["text"],
                                        "file_uuid" => $note["params"]["file_uuid"],
                                        "file_name" => $note["params"]["file_name"],
                                        "version_uuid" => $note["params"]["version_uuid"] ?? null, // Передаем UUID версии, если он есть
                                        "original_name" => $note["params"]["original_name"] ?? null, // Если оригинальное имя файла есть
                                        "is_drive_attachment" => $note["params"]["is_drive_attachment"] ?? 0 // Флаг для Google Drive, если есть
                                    ];
                                } else {
                                    writeLog("Ошибка: недостающие параметры для примечания с файлом (file_uuid, file_name).", "ERROR");
                                }
                                break;
                        }
                        $noteResult = $amoV4Client->POSTRequestApi("leads/$newLeadId/notes", [$noteData]);
                        if ($noteResult) {
                            writeLog("Примечание успешно скопировано", "SUCCESS");
                        }
                    }
                }

                // Копируем задачи
                foreach ($tasksData as $task) {
                    $taskData = [
                        "text" => $task["text"],
                        "complete_till" => $task["complete_till"],
                        "entity_id" => $newLeadId,
                        "entity_type" => "leads"
                    ];

                    if (isset($task["responsible_user_id"])) {
                        $taskData["responsible_user_id"] = $task["responsible_user_id"];
                    }

                    $taskResult = $amoV4Client->POSTRequestApi("tasks", [$taskData]);
                    if ($taskResult) {
                        writeLog("Задача успешно скопирована", "SUCCESS");
                    }
                }
                $copiedCount++;
                writeLog("Сделка ID: {$lead['id']} успешно скопирована", "SUCCESS");

            } catch (Exception $e) {
                writeLog("Ошибка при копировании сделки ID: {$lead['id']}: " . $e->getMessage(), "ERROR");
            }
        }

        writeLog("Функция copyLeadsOnBudget завершена. Скопировано сделок: $copiedCount", "SUCCESS");
    } catch (Exception $e) {
        writeLog("Ошибка в функции copyLeadsOnBudget: " . $e->getMessage(), "ERROR");
    }
}

try {

    $amoV4Client = new AmoCrmV4Client(SUB_DOMAIN, CLIENT_ID, CLIENT_SECRET, CODE, REDIRECT_URL);

    // Получаем данные о воронках и статусах
    $pipelineData = getPipelinesAndStatuses($amoV4Client);
    if (!$pipelineData) {
        throw new Exception("Не удалось получить данные о воронках и статусах");
    }

    // Вызываем функции обработки сделок
    updateLeadsOnBudget($amoV4Client, $pipelineData);
    copyLeadsOnBudget($amoV4Client, $pipelineData);

}
catch (Exception $e) {
    file_put_contents("ERROR_LOG.txt", 'Ошибка: ' . $e->getMessage() . PHP_EOL . 'Код ошибки:' . $e->getCode());
    echo "\nПроизошла ошибка при выполнении скрипта. Подробности в логах.\n";
}
