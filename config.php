<?php

/**
 * Configuração do agente de sincronização leads2mautic.
 *
 * Este arquivo é seguro para commitar: não contém senhas ou segredos.
 * Todas as credenciais (MySQL, NeverBounce, Mautic) são lidas do .env.
 *
 * Convenção de variáveis por fonte:
 *   DB_{LABEL_MAIÚSCULO}_DSN   — DSN PDO completo (host, dbname, charset)
 *   DB_{LABEL_MAIÚSCULO}_USER  — usuário MySQL
 *   DB_{LABEL_MAIÚSCULO}_PASS  — senha MySQL
 */

return [

    // Caminho para o arquivo SQLite de estado
    'state_db' => __DIR__ . '/data/state.db',

    // ---------------------------------------------------------------
    // FONTES DE DADOS
    //
    // Cada fonte define:
    //   label   — nome identificador para logs
    //   dsn     — DSN PDO da fonte MySQL
    //   user    — usuário MySQL
    //   pass    — senha MySQL
    //   query   — SELECT que DEVE retornar a coluna `email`
    //   columns — mapa alias => tipo para as colunas de dados retornadas
    //             (email é sempre PK e não precisa ser declarado aqui)
    //
    // Tipos suportados: TEXT | INTEGER | REAL
    //   TEXT    — strings, datas, datetimes (ex: nome, cadastro, lastlogin)
    //   INTEGER — contadores inteiros (ex: logincount)
    //   REAL    — valores monetários / decimais (ex: valorpix, valorstripe)
    //
    // O alias do SELECT deve ser idêntico ao nome da chave em 'columns'.
    // O mesmo nome será usado como alias do campo no Mautic.
    // ---------------------------------------------------------------
    'sources' => [
        [
            'label'   => 'logins',
            'dsn'     => getenv('DB_LOGINS_DSN'),
            'user'    => getenv('DB_LOGINS_USER'),
            'pass'    => getenv('DB_LOGINS_PASS'),
            'query'   => "
                SELECT MAX(nome)       AS firstname,
                       email,
                       MIN(cadastro)   AS cadastro,
                       SUM(logincount) AS logincount,
                       MAX(lastlogin)  AS lastlogin
                FROM login
                WHERE email IS NOT NULL
                  AND email != ''
                GROUP BY email
            ",
            'columns' => [
                'firstname'  => 'TEXT',
                'cadastro'   => 'TEXT',
                'logincount' => 'INTEGER',
                'lastlogin'  => 'TEXT',
            ],
        ],
        [
            'label'   => 'pix',
            'dsn'     => getenv('DB_PIX_DSN'),
            'user'    => getenv('DB_PIX_USER'),
            'pass'    => getenv('DB_PIX_PASS'),
            'query'   => "
                SELECT MAX(nome)    AS firstname,
                       email,
                       SUM(valor)   AS valorpix
                FROM Pix
                WHERE status = 'CONCLUIDA'
                  AND servidor IN ('Fenix', 'Aegir')
                  AND email IS NOT NULL
                  AND email != ''
                GROUP BY email
            ",
            'columns' => [
                'firstname' => 'TEXT',
                'valorpix'  => 'REAL',
            ],
        ],
        [
            'label'   => 'stripe',
            'dsn'     => getenv('DB_STRIPE_DSN'),
            'user'    => getenv('DB_STRIPE_USER'),
            'pass'    => getenv('DB_STRIPE_PASS'),
            'query'   => "
                SELECT MAX(nome)  AS firstname,
                       email,
                       SUM(valor) AS valorstripe
                FROM Stripe
                WHERE status = 'CONCLUIDA'
                  AND servidor IN ('Fenix', 'Aegir')
                  AND email IS NOT NULL
                  AND email != ''
                GROUP BY email
            ",
            'columns' => [
                'firstname'   => 'TEXT',
                'valorstripe' => 'REAL',
            ],
        ],
    ],

    // ---------------------------------------------------------------
    // SANITIZAÇÃO (NeverBounce)
    // ---------------------------------------------------------------
    'sanitize' => [
        'batch_size' => 100,
        'order_by'   => 'lastlogin',  // coluna da tabela contacts para ordenação
        'order_dir'  => 'DESC',       // ASC ou DESC
        // filtro SQL extra
        'where'      => null,
    ],

    // ---------------------------------------------------------------
    // EXPORTAÇÃO (Mautic)
    // ---------------------------------------------------------------
    'export' => [
        'batch_size'  => 100,
        'order_by'    => 'lastlogin',  // coluna da tabela contacts para ordenação
        'order_dir'   => 'DESC',       // ASC ou DESC
        // filtro SQL extra
        'where'       => 'last_export IS NULL OR last_import > last_export',
    ],

];
