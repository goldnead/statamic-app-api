<?php

return [
    'groups' => [
        'requests' => [
            'title' => 'Anfragen',
            'description' => 'Wie viele Anfragen ein Client schicken darf, wohin der Link zum Zurücksetzen führt und welche Felder der API-Nutzer zeigt. Präfix, Middleware und Bereiche stehen in config/app-api.php.',
        ],
        'checkout' => [
            'title' => 'Kasse und Portal',
            'description' => 'Wie lange eine gestartete Kasse erneut ausgegeben wird und wer einen Link ins Kundenportal bekommt.',
        ],
        'account' => [
            'title' => 'Export und Token',
            'description' => 'Wie lange ein Export geladen werden kann und wie lange ein neues Zugangstoken gilt.',
        ],
    ],
    'fields' => [
        'routes_rate_limit' => ['label' => 'Anfragen pro Minute', 'description' => 'Je Nutzer, bei Gästen je Adresse. 0 schaltet die Grenze aus.'],
        'auth_password_reset_url' => ['label' => 'Seite für ein neues Passwort', 'description' => 'Ein Pfad der App, z. B. /passwort-neu. Die Mail verlinkt dorthin mit ?token=. Leer: die Seite von Statamic.'],
        'user_fields' => ['label' => 'Weitere Nutzerfelder', 'description' => 'Felder aus dem Nutzer-Blueprint, die die API mitschickt. Nie ein Geheimnis.'],
        'checkout_idempotency_seconds' => ['label' => 'Dieselbe Kasse erneut (Sekunden)', 'description' => 'In dieser Zeit antwortet dieselbe Anfrage mit der ersten Kasse statt mit einer zweiten Zahlung.'],
        'checkout_return_url' => ['label' => 'Rückkehrseite nach dem Bezahlen', 'description' => 'Ein Pfad auf dieser Site. Leer: die Seite aus statamic-payments.'],
        'billing_return_url' => ['label' => 'Rückkehrseite nach dem Zahlungsmittelwechsel', 'description' => 'Ein Pfad auf dieser Site, etwa der Reiter Kauf der App. Leer: die Startseite.'],
        'billing_cancellation_copy_to_team' => ['label' => 'Kündigungsbestätigung auch an das Team', 'description' => 'Kündigt jemand einen Team-Vertrag, geht die Bestätigung zusätzlich an die Rechnungsadresse des Teams, wenn es eine gibt und sie eine andere ist.'],
        'portal_require_verified_email' => ['label' => 'Portal nur mit bestätigter Adresse', 'description' => 'Empfohlen. Ohne das sieht, wer sich mit fremder Adresse registriert, die Bestellungen dieser Person.'],
        'export_link_minutes' => ['label' => 'Export-Link gilt (Minuten)', 'description' => 'Danach wird die Datei gelöscht.'],
        'tokens_expires_after_days' => ['label' => 'Token gelten (Tage)', 'description' => 'Leer: ein Token gilt, bis es entzogen wird.'],
    ],
];
