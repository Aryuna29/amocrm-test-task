<?php
require_once __DIR__ . '/src/AmoCrmV4Client.php';

define('SUB_DOMAIN', 'aryunats00');
define('CLIENT_ID', '52d80ccf-dbf1-4ca3-b655-f487e58f40a9');
define('CLIENT_SECRET', 'pMBFN1BoM2zBzL55kxLG6WuI6Y9MxkSM4dg5oBTeb1H9FIbCT1AbNF7fhEsP4pHt');
define('CODE', 'def5020045637fd5aaa88231ffa562677a227351695ff065dc227c85e79d55cf79a868f16192aa270491ce2cd9d5ba4937d0187f77e870614a0313d544162b8f0c0811d5c96785e153a23009e9848a051205d820118067ab16b4406b31d84262c810c3a337bb5aac0e8247c1feb3c211be28347b77e16262c95b9178ad981f2d99265349534a699ef1d691dd1d6179ad71a2c4320a33765489f625fc84c8d85a33ef47053425c960a5d7280ad7833a7f3638cd4caf85066314f9a7e604fcf86a4ec44e3c4c3c3486a0a9e473240f6c50d2f03675365536f9b70f91727c4d8f9ac98e6e1db6e84c1d656a9b895050a414af588aaf1abf8f8da8e6778029482df7ef9f2e39bcf0c27e4a32a1ab9f511a70f1f62ab8711310e5241baf00b23a547aead18bf917158d0f3caaea15889b6c08b5953fd5d0a47c689833f921dc673aab5d10cef8f85655e8840d0ff13d4168278ee38063e50f73f66fc47b8bf102527009e67765d0b0d82d67a914b4839b53384f9aea8b61cfd8ec7ea48561f566f472304189b98f34288920c3a38d251d788eeef29fc510022778a215ce582b6ed894d455a2e3ebade9eb3bfe6a3252bc7d911a20638a6f6ac6bb945b18e88f6c676334878cc51bd1b500c1c60f9988617191a0d8db1e0f39af3903c305e520d6107bccb1edb366ee3cedd10894c322');
define('REDIRECT_URL', 'https://aryunats00.amocrm.ru');

echo "<pre>";

try {

    $amoV4Client = new AmoCrmV4Client(SUB_DOMAIN, CLIENT_ID, CLIENT_SECRET, CODE, REDIRECT_URL);
    function updateLeadsOnBudget($amoV4Client)
    {
        //Перенос сделок с бюджетом > 5000 из "Заявка" в "Ожидание клиента"
        $leadsClientApplication = $amoV4Client->GETAll("leads", [
            "filter[statuses][0][pipeline_id]" => 9537262, //id Воронка
            "filter[statuses][0][status_id]" => 76225426 //id Заявка
        ]);

        array_filter( $leadsClientApplication, function ($lead) use ($amoV4Client) { // использование анонимной функции
            if ($lead['price'] > 5000) {
                $amoV4Client->POSTRequestApi("leads/{$lead['id']}", [
                    "status_id" => 76225434 //id Ожидание клиента
                ], "PATCH"); //метод "PATCH" редактирование сделок
            }
        });
    }

    function copyLeadsOnBudget($amoV4Client)
    {
        // копирование сделки на этапе “Клиент подтвердил” при условии, что бюджет сделки = равен 4999
        $leadsClientConfirmation = $amoV4Client->GETAll("leads", [
            "filter[statuses][0][pipeline_id]" => 9537262,
            "filter[statuses][0][status_id]" => 76234074,
            "filter[price]" => 4999 // Фильтр по цене
        ]);

        foreach ($leadsClientConfirmation as $lead) {

            $notes = $amoV4Client->GETRequestApi("leads/{$lead['id']}/notes"); //получение примечаний
            $tasks = $amoV4Client->GETRequestApi("tasks", [ // получение задач
                "filter[entity_id]" => $lead['id'], // id сделки
                "filter[entity_type]" => "leads" // тип сущности
            ]);

            $newLead = $amoV4Client->POSTRequestApi("leads", [[ // создание копии-сделки
                "name" => $lead["name"] . " (копия)",
                "price" => $lead["price"],
                "status_id" => 76225434, //создание на этапе "Ожидание клиента"
                "pipeline_id" => $lead["pipeline_id"] , // воронка
                "_embedded" => [
                    "contacts" => array_map(function ($contact) {
                        return ['id' => $contact['id']]; //  id контактов
                    }, $lead["_embedded"]["contacts"]),
                    "companies" => array_map(function ($company) {
                        return ['id' => $company['id']]; //  id компаний
                    }, $lead["_embedded"]["companies"])
                ]
            ]]);
            $newLeadId = $newLead['_embedded']['leads'][0]['id']; // id новой сделки

            array_filter($notes['_embedded']['notes'], function ($note) use ($newLeadId, $amoV4Client) {
                if (isset($note['note_type']) && $note['note_type'] === 'common') { // проверка
                    $amoV4Client->POSTRequestApi("leads/$newLeadId/notes", [[
                        "note_type" => "common",
                        "params" => ["text" => $note["params"]["text"]] //берем текст из старой сделки
                    ]]);
                }
            });

            array_filter($tasks['_embedded']['tasks'], function ($task) use ($newLeadId, $amoV4Client) {
                $amoV4Client->POSTRequestApi("tasks", [[ //Создание копии задач из сделки-донора с сохранением всех данных и прикрепить новые задачи к сделке.
                    "text" => $task["text"],
                    "complete_till" => $task["complete_till"],
                    "entity_id" => $newLeadId,
                    "entity_type" => "leads",
                    "responsible_user_id" => $task["responsible_user_id"]
                ]]);
            });
        }


    }

    updateLeadsOnBudget($amoV4Client); // вызываем функции
    copyLeadsOnBudget($amoV4Client);
    echo "\nГотово!\n";
}


catch (Exception $ex) {
    var_dump($ex);
    file_put_contents("ERROR_LOG.txt", 'Ошибка: ' . $ex->getMessage() . PHP_EOL . 'Код ошибки:' . $ex->getCode());
}
