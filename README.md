# leads2mautic

Agente PHP de sincronização contínua de leads para o Mautic.

Importa contatos de múltiplas bases MySQL, valida emails via NeverBounce e exporta para o Mautic — com estado persistido em SQLite e operações independentes via CLI.

---

## Como funciona (visão geral)

O agente opera em **três etapas independentes**, cada uma disparada por uma flag CLI:

```
MySQL (N fontes)  ──[--import]──▶  state.db (SQLite)  ──[--sanitize]──▶  NeverBounce
                                                                               │
                                        Mautic  ◀──[--export]──  state.db ◀──┘
```

O modo `--statistics` oferece uma visão instantânea do estado do banco sem alterar nada.

**Por que separar em etapas?**

- `--import` pode rodar a qualquer hora para manter o SQLite atualizado.
- `--sanitize` consome créditos NeverBounce — roda só quando há emails novos a validar.
- `--export` consome API do Mautic — pode ser agendado com frequência maior que o sanitize.

Cada etapa lê e grava no mesmo arquivo SQLite (`data/state.db`), que funciona como o estado central do agente. Isso permite rodar as etapas em horários diferentes no cron sem perder contexto.

---

## Requisitos

- PHP 8.1+ com extensões: `pdo_mysql`, `sqlite3`, `curl`
- Acesso às bases MySQL de origem
- Conta NeverBounce com API key
- Instância Mautic com usuário de API habilitado

Sem dependências externas — não é necessário Composer.

---

## Instalação

```bash
git clone <repo> leads2mautic
cd leads2mautic
cp .env.example .env
# edite o .env com suas credenciais
```

---

## Configuração

A configuração é dividida em dois arquivos com responsabilidades distintas:

| Arquivo | Conteúdo | Commitar? |
|---|---|---|
| `.env` | Credenciais, senhas, API keys | **Não** |
| `config.php` | Fontes, queries, parâmetros de operação | **Sim** |

---

### `.env` — Credenciais

```env
# Fonte: logins
DB_LOGINS_DSN=mysql:host=HOST;dbname=NOME;charset=utf8mb4
DB_LOGINS_USER=usuario
DB_LOGINS_PASS=senha

# Fonte: pix
DB_PIX_DSN=mysql:host=HOST;dbname=NOME;charset=utf8mb4
DB_PIX_USER=usuario
DB_PIX_PASS=senha

# Fonte: stripe
DB_STRIPE_DSN=mysql:host=HOST;dbname=NOME;charset=utf8mb4
DB_STRIPE_USER=usuario
DB_STRIPE_PASS=senha

# NeverBounce
NEVERBOUNCE_API_KEY=nb_live_...

# Mautic
MAUTIC_BASE_URL=https://mautic.exemplo.com
MAUTIC_USER=api_user
MAUTIC_PASS=api_pass
```

---

### `config.php` — Fontes e parâmetros

#### Seção `sources` — de onde importar

Cada fonte é um array com as seguintes chaves:

| Chave | Descrição |
|---|---|
| `label` | Nome identificador, usado nos logs |
| `dsn` | DSN PDO completo da base MySQL de origem |
| `user` / `pass` | Credenciais MySQL (lidas do `.env`) |
| `query` | `SELECT` que **obrigatoriamente** retorna a coluna `email` |
| `columns` | Mapa `alias => tipo` das demais colunas retornadas pela query |

**Tipos suportados em `columns`:**

| Tipo | Usar para |
|---|---|
| `TEXT` | Strings, datas, datetimes |
| `INTEGER` | Contadores, IDs inteiros |
| `REAL` | Valores monetários, decimais |

O alias do `SELECT` deve ser idêntico à chave em `columns`. O mesmo nome é usado como alias do campo no Mautic. A coluna `email` é sempre a chave primária e não precisa ser declarada em `columns`.

**Exemplo:**

```php
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
],
```

Múltiplas fontes podem declarar a mesma coluna (ex: `firstname`). O UPSERT por fonte **atualiza apenas as colunas da fonte atual** — as colunas das outras fontes são preservadas. Isso significa que dados de fontes distintas são mesclados por email no SQLite.

---

#### Seção `sanitize` — parâmetros da validação NeverBounce

```php
'sanitize' => [
    'batch_size' => 100,       // quantos emails enviar por execução
    'order_by'   => 'lastlogin', // coluna do SQLite para ordenar a fila
    'order_dir'  => 'DESC',    // ASC ou DESC
    'where'      => null,      // filtro SQL extra (ver abaixo)
],
```

O `--sanitize` processa apenas emails com `sanitize_status IS NULL` no SQLite — ou seja, emails que ainda não passaram pela validação. O `order_by` controla qual email sai na frente da fila (ex: `lastlogin DESC` processa primeiro quem logou mais recentemente).

**Parâmetro `where`**

Permite adicionar uma condição SQL extra ao critério de busca dos emails a sanitizar, sem modificar o código. O valor é um fragmento SQL puro que será incluído como `AND (...)` na query:

```php
// Sanitizar apenas emails do Gmail
'where' => "email LIKE '%@gmail.com'",

// Sanitizar apenas quem logou após uma data
'where' => "lastlogin > '2024-01-01'",

// Sem filtro extra (comportamento padrão)
'where' => null,
```

A query gerada com `where` preenchido fica:
```sql
SELECT email FROM contacts
WHERE sanitize_status IS NULL AND (<seu where>)
ORDER BY "lastlogin" DESC
LIMIT 100
```

---

#### Seção `export` — parâmetros da exportação para o Mautic

```php
'export' => [
    'batch_size' => 100,        // quantos contatos exportar por execução
    'order_by'   => 'lastlogin', // coluna do SQLite para ordenar a fila
    'order_dir'  => 'DESC',     // ASC ou DESC
    'where'      => 'last_export IS NULL OR last_import > last_export',
],
```

O `--export` processa apenas contatos com `sanitize_status = 'valid'`. O parâmetro `where` define quais desses contatos entram na fila de exportação.

**Parâmetro `where`**

O valor padrão `last_export IS NULL OR last_import > last_export` implementa a seguinte lógica:

- `last_export IS NULL` — contato ainda nunca foi exportado para o Mautic.
- `last_import > last_export` — ao menos uma coluna de dado mudou num `--import` ocorrido após a última exportação, ou seja, há dados novos a sincronizar.

Como `last_import` só é atualizado quando algum valor realmente muda, essa condição não gera falsos positivos: runs de `--import` que não trouxeram alterações não recolocam o contato na fila de exportação.

Outros exemplos de uso:

```php
// Exportar apenas contatos com transação financeira
'where' => 'last_export IS NULL OR (last_import > last_export AND (valorpix > 0 OR valorstripe > 0))',

// Exportar todos os válidos, sempre (sem controle de re-exportação)
'where' => null,

// Forçar re-exportação de todos os contatos (útil para sincronização inicial)
'where' => '1=1',
```

A query gerada com o `where` padrão fica:
```sql
SELECT * FROM contacts
WHERE sanitize_status = 'valid'
  AND (last_export IS NULL OR last_import > last_export)
ORDER BY "lastlogin" DESC
LIMIT 100
```

> **Nota de segurança:** o `where` é um fragmento SQL inserido diretamente na query, intencional e consistente com o padrão das queries em `sources`. O `config.php` é controlado pelo desenvolvedor — não há risco de SQL injection externo.

---

## Uso

```bash
# 1. Importar contatos de todas as fontes MySQL para o SQLite
php agent.php --import

# 2. Validar emails pendentes via NeverBounce
php agent.php --sanitize

# 3. Exportar contatos válidos para o Mautic
php agent.php --export

# Exibir estatísticas do banco (somente leitura, sem credenciais necessárias)
php agent.php --statistics
```

Os modos `--import`, `--sanitize` e `--export` são protegidos por `flock` — não é possível rodar duas instâncias do mesmo modo simultaneamente. Se uma instância já estiver rodando, o agente imprime um aviso e encerra. O modo `--statistics` é somente leitura e não usa lock.

### Cron sugerido

```cron
0    2 * * *  cd /caminho/leads2mautic && php agent.php --import   >> /dev/null 2>&1
0    * * * *  cd /caminho/leads2mautic && php agent.php --sanitize >> /dev/null 2>&1
*/15 * * * *  cd /caminho/leads2mautic && php agent.php --export   >> /dev/null 2>&1
```

As frequências refletem as características de cada etapa: `--import` traz dados brutos uma vez ao dia; `--sanitize` consome créditos NeverBounce e roda a cada hora para validar emails novos sem desperdício; `--export` é leve e roda a cada 15 minutos para manter o Mautic atualizado rapidamente após cada mudança. Cada etapa é independente — o agente sabe exatamente quais registros ainda precisam ser processados em cada execução.

---

## Comportamento detalhado por modo

### `--import`

1. Para cada fonte em `config['sources']`:
   - Conecta via PDO com as credenciais do `.env`
   - Executa a query configurada
   - Verifica se há novas colunas a adicionar no SQLite (via `ALTER TABLE ADD COLUMN`, idempotente)
   - Faz UPSERT de todos os registros: insere novos e atualiza existentes, tocando apenas as colunas desta fonte
   - Atualiza `last_import = datetime('now')` apenas quando ao menos uma coluna de dado realmente muda; se os valores forem idênticos aos já gravados, `last_import` é preservado
2. Falha em uma fonte é isolada — as demais fontes continuam sendo processadas

O UPSERT por fonte é fundamental: se a fonte `logins` atualiza `firstname` e `lastlogin`, e a fonte `pix` atualiza `valorpix`, ambas podem rodar em qualquer ordem sem sobrescrever os dados da outra.

### `--sanitize`

1. Busca emails com `sanitize_status IS NULL` no SQLite (respeitando `batch_size`, `order_by`, `order_dir` e `where`)
2. Cria um job bulk no NeverBounce com todos os emails do lote
3. Aguarda conclusão do job com poll a cada 3 segundos (timeout: 300s)
4. Coleta os resultados paginados
5. Grava `sanitize_status` e `last_sanitize` para cada email

Resultados possíveis do NeverBounce: `valid`, `invalid`, `disposable`, `catchall`, `unknown`.

Somente contatos com `sanitize_status = 'valid'` são elegíveis para exportação.

Requisições ao NeverBounce usam retry com backoff exponencial (até 5 tentativas), cobrindo falhas de rede e rate limiting (HTTP 429).

### `--export`

1. Busca contatos com `sanitize_status = 'valid'` no SQLite (respeitando `batch_size`, `order_by`, `order_dir` e `where`)
2. Para cada contato:
   - Busca no Mautic pelo email via `GET /api/contacts?search=email:...`
   - Se existir: atualiza com `PATCH /api/contacts/{id}/edit`
   - Se não existir: cria com `POST /api/contacts/new`
   - Em caso de sucesso: atualiza `last_export = datetime('now')` no SQLite
   - Em caso de falha: mantém `last_export` inalterado — o contato volta à fila automaticamente na próxima execução
3. Campos enviados ao Mautic: todas as colunas não-meta com valor não-nulo

Colunas meta (nunca enviadas ao Mautic): `email`, `last_import`, `last_export`, `last_sanitize`, `sanitize_status`.

Requisições ao Mautic usam retry com backoff exponencial (até 4 tentativas).

### `--statistics`

Exibe uma visão consolidada do estado do banco sem alterar nenhum dado. Não requer credenciais, não adquire lock e pode ser executado a qualquer momento — inclusive enquanto outros modos estão rodando.

```
──────────────────────────────────────────────
 ESTATÍSTICAS — leads2mautic
──────────────────────────────────────────────

 Total de emails na base               12.547

 ── Higienização ─────────────────────────────
 Pendentes (fila)                       1.203    9%
 Higienizados                          11.344   90%
   valid                               10.102
   invalid                                812
   disposable                             298
   catchall                               112
   unknown                                 20

 ── Exportação ───────────────────────────────
 Exportados                             9.874   97% dos válidos
 Elegíveis para exportar                  228    2% dos válidos

──────────────────────────────────────────────
```

**Campos exibidos:**

| Campo | Descrição |
|---|---|
| Total de emails na base | Total de registros distintos na tabela `contacts` |
| Pendentes (fila) | Emails com `sanitize_status IS NULL` — aguardam validação |
| Higienizados | Emails com qualquer `sanitize_status` já definido |
| *(breakdown por status)* | Contagem de cada resultado NeverBounce (`valid`, `invalid`, etc.) |
| Exportados | Contatos com `last_export IS NOT NULL` (já enviados ao Mautic ao menos uma vez) |
| Elegíveis para exportar | Contatos `valid` com `last_export IS NULL OR last_import > last_export` — prontos para a próxima execução de `--export` |

---

## Arquitetura

```
leads2mautic/
├── agent.php               # Entry point CLI — parse de args, lock, bootstrap, dispatch
├── config.php              # Fontes, queries e parâmetros (seguro para commitar)
├── .env                    # Credenciais (nunca commitado)
├── .env.example            # Template das variáveis de ambiente
├── data/
│   └── state.db            # Estado SQLite (nunca commitado)
└── src/
    ├── Logger.php          # JSON logging com rotação automática a cada 10 MB
    ├── StateDB.php         # SQLite3: schema auto-evolutivo, upsert por fonte, queries de fila
    ├── ImportService.php   # Lê fontes MySQL e popula o SQLite via upsert
    ├── SanitizeService.php # Submete emails ao NeverBounce e grava resultados
    └── ExportService.php   # Sincroniza contatos válidos com a API REST do Mautic
```

---

## Schema SQLite (`contacts`)

| Coluna | Tipo | Descrição |
|---|---|---|
| `email` | TEXT NOCASE PK | Email normalizado em lowercase |
| `last_import` | TEXT | Timestamp do último `--import` que **alterou** dados deste contato; preservado se nenhum valor mudou |
| `last_export` | TEXT | Timestamp do último `--export` bem-sucedido |
| `last_sanitize` | TEXT | Timestamp da última validação NeverBounce |
| `sanitize_status` | TEXT | Resultado NeverBounce: `valid`, `invalid`, `disposable`, etc. |
| *(dinâmicas)* | TEXT / INTEGER / REAL | Colunas adicionadas automaticamente conforme as fontes configuradas |

O schema evolui automaticamente: ao detectar uma coluna nova em `config.php`, o agente executa `ALTER TABLE ADD COLUMN` antes do primeiro UPSERT. Não é necessária nenhuma migração manual ao adicionar fontes ou colunas.

Emails são normalizados para lowercase antes do armazenamento. A PK usa `COLLATE NOCASE` como segunda camada de proteção contra duplicatas por variação de capitalização (ex: `User@Gmail.com` e `user@gmail.com` são o mesmo contato).

---

## Adicionando uma nova fonte

**1. Adicione as credenciais ao `.env`:**

```env
DB_NOVA_DSN=mysql:host=HOST;dbname=NOME;charset=utf8mb4
DB_NOVA_USER=usuario
DB_NOVA_PASS=senha
```

**2. Adicione a entrada em `config.php`:**

```php
[
    'label'   => 'nova',
    'dsn'     => getenv('DB_NOVA_DSN'),
    'user'    => getenv('DB_NOVA_USER'),
    'pass'    => getenv('DB_NOVA_PASS'),
    'query'   => "
        SELECT email, MAX(campo) AS campo
        FROM tabela
        WHERE email IS NOT NULL AND email != ''
        GROUP BY email
    ",
    'columns' => [
        'campo' => 'TEXT',
    ],
],
```

**3. Rode `--import`** — as colunas novas são adicionadas automaticamente ao SQLite. Não é necessário recriar o banco.

---

## Testes

A suite de testes cobre `StateDB` e o filtro local de `SanitizeService`. Não requer nenhuma dependência externa — usa SQLite em memória e reflexão PHP.

```bash
php tests/run.php
```

Saída esperada:

```
StateDBTest
────────────────────────────────────────────────────────────────
  ✓ testNewEmailGetsLastImport (1)
  ✓ testUpsertSameDataPreservesLastImport (1)
  ...

SanitizeServiceTest
────────────────────────────────────────────────────────────────
  ✓ testValidEmailReturnsNull (3)
  ✓ testMailinatorIsDisposable (1)
  ...

All 48 tests passed.
```

### Cobertura

| Suite | Casos | O que cobre |
|---|---|---|
| `StateDBTest` | 29 | `last_import` condicional (8 casos), normalização de email, `ensureColumns`, fila de sanitização, fila de exportação com cenários de re-export |
| `SanitizeServiceTest` | 19 | Validação de sintaxe RFC, blocklist de domínios descartáveis, edge cases de capitalização e subdomínio, verificação de todos os 42 domínios da lista em loop |

Os testes temporais usam `setField()` para forçar timestamps diretamente no SQLite, eliminando dependência de `sleep()` e evitando flakiness.

---

## Logs

Gravados em `agent.log` (JSON, uma entrada por linha). Rotacionado automaticamente ao atingir 10 MB — o arquivo anterior é renomeado para `agent.log.YYYYMMDDHHMMSS.old`.

```json
{
  "ts": "2026-02-27T02:00:01+00:00",
  "nivel": "INFO",
  "batch": "import_abc123",
  "mensagem": "Fonte concluída: logins",
  "contexto": {
    "linhas": 4821,
    "colunas": {
      "firstname": "TEXT",
      "cadastro": "TEXT",
      "logincount": "INTEGER",
      "lastlogin": "TEXT"
    }
  }
}
```

Campos: `ts` (ISO 8601), `nivel` (`INFO` / `WARN` / `ERROR`), `batch` (ID único por execução do modo), `mensagem`, `contexto` (dados adicionais variáveis por evento).
