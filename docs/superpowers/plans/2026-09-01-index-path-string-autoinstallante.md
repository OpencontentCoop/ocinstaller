# Indice `path_string` auto-installante — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** far comparire un indice `varchar_pattern_ops` su `ezcontentobject_tree.path_string` su tutti i tenant, sia Boat che SaaS, riusando un solo script idempotente per entrambe le piattaforme — automatico su Boat (tramite il normale ciclo di deploy), a lancio manuale ma batch su SaaS (nessun equivalente dell'entrypoint container esiste lì).

**Architecture:** un piccolo script PHP standalone in `ocinstaller` (stesso schema di `bin/install_pgcrypto.php`, connessione PDO diretta, nessun passaggio dal motore a step/versione di `ocinstall`), riusabile identico su entrambe le piattaforme perché parla solo PDO/host/porta/utente/password/database — nessuna dipendenza da Docker o da un particolare deploy. Su Boat viene invocato **incondizionatamente** — non dietro `RUN_INSTALLER` — dall'entrypoint del container CMS (`prodotto-sito-comunale`). Su SaaS non esiste un entrypoint equivalente (è un pool di server PHP-FPM condiviso, non un container per tenant): il lancio è **manuale**, un'operazione batch su tutte le istanze fatta da un ops (Task 4). In entrambi i casi lo script è idempotente (`IF NOT EXISTS`), auto-risanante (droppa e ricrea se trova un indice `invalid` lasciato da una build interrotta), non bloccante (un suo fallimento non deve mai impedire l'avvio del container/l'esecuzione del batch sugli altri tenant).

**Tech Stack:** PHP + PDO (pgsql), bash (entrypoint Boat, loop manuale SaaS), Composer (`dev-master` su `opencontent/ocinstaller`, in tre repo: `ocinstaller` stesso, `prodotto-sito-comunale`, `saasopenpa-distribution`).

**Spec:** discussione in sessione (nessun documento separato). Riscontro empirico che ha motivato questo piano: `EXPLAIN ANALYZE` su un tenant Boat reale mostrava il planner ignorare il btree esistente su `path_string` per query `LIKE 'prefix%'`, instradandosi invece da una condizione poco selettiva e toccando l'equivalente di ~69.000 letture di buffer per 239 righe.

## Global Constraints

- **Non incondizionato = non basta.** Il primo tentativo di questo piano proponeva un'esecuzione via `RUN_INSTALLER=true` o `--only-step`: entrambi si applicano solo alle nuove attivazioni o richiedono comunque un trigger manuale per sito, perché in `install.sh` (righe 86-97) lo step viene **saltato** quando `RUN_INSTALLER=false` — che è lo stato di *tutti* i siti già live. La chiamata al nuovo script deve stare **fuori** da quel blocco `if`, altrimenti il problema di rollout torna identico a prima solo con un tool diverso.
- **Mai `CREATE INDEX` senza `CONCURRENTLY`**: non deve mai prendere un lock esclusivo su una tabella di un sito live.
- **Mai dentro una transazione esplicita**: `CREATE INDEX CONCURRENTLY` non può girare in un blocco di transazione — lo script non deve chiamare `beginTransaction()`, e il file SQL non va copiato dal pattern `BEGIN;...COMMIT;` usato altrove in questo repo (es. `installer/sql/check_eztags_sequence.sql` — quel pattern lì è corretto per quel caso, ma qui romperebbe tutto).
- **Mai bloccante**: un fallimento di questo script (anche per una race legittima — vedi Task 1) non deve mai far fallire l'avvio del container. A differenza di `install_pgcrypto.php` (che con `|| exit 2` blocca lo startup), questa chiamata non deve avere `|| exit N`.
- **`PDO::ATTR_ERRMODE` va impostato esplicitamente a `PDO::ERRMODE_EXCEPTION`.** Senza, `PDOStatement::exec()` su un errore SQL ritorna `false` in silenzio invece di lanciare — lo script non se ne accorgerebbe nemmeno lui. `install_pgcrypto.php` non lo fa (probabile gap preesistente, fuori scope qui) — non replicare quella omissione nel nuovo script.
- **`ocinstaller` non è un repo in eccezione alla regola di branching**: feature branch da `master` + conferma esplicita prima del push, come da regola generale del profilo (a differenza di `boat-ez-tools`/`saas-tools`/`saasopenpa-distribution-prod`/`cms`, che hanno eccezioni diverse).
- **`prodotto-sito-comunale` (repo `cms`) ha l'eccezione**: si può lavorare direttamente su `master`, ma resta valida la regola generale di non fare push senza conferma esplicita di aver testato le modifiche.
- **Ordine tra i repo, obbligato**: Task 2/3 (in `prodotto-sito-comunale`) e Task 4 (in `saasopenpa-distribution`) dipendono tutti dal merge del Task 1 (in `ocinstaller`) — il file che invocano deve esistere nel commit puntato dal rispettivo `composer.lock` prima di procedere.
- **SaaS non ha un meccanismo di trigger automatico**: nessun entrypoint, nessun cron esistente che giri periodicamente su tutte le istanze rilanciando l'installer (verificato: `run_updater.sh` non è referenziato da nessun crontab). Per decisione esplicita, il Task 4 resta un'operazione **manuale**, lanciata da un ops quando decide di farlo — non è schedulata, non è wired a un deploy.
- **Nessuna credenziale reale in questo piano**: le password DB dei tenant SaaS vivono in `html/settings/siteaccess/<tenant>_frontend/site.ini.append.php` di ciascun repo — il Task 4 legge da lì a runtime, non le riporta qui.

---

## Task 1: Script idempotente e auto-risanante in `ocinstaller`

**Repo:** `ocinstaller` (`git@github.com:.../ocinstaller.git` — verificare remote esatto con `git remote -v`)

**Files:**
- Create: `bin/install_path_pattern_index.php`

**Interfaces:**
- Consumato da Task 3 (`prodotto-sito-comunale/docker/php/scripts/install.sh`) via `php vendor/opencontent/ocinstaller/bin/install_path_pattern_index.php --host=... --port=... --user=... --password=... --database=...` — stessa interfaccia CLI di `bin/install_pgcrypto.php`, stesso set di opzioni.

- [ ] **Step 1: Creare branch**

```bash
cd ocinstaller
git checkout master && git pull
git checkout -b feature/path-pattern-index-autoinstall
```

- [ ] **Step 2: Scrivere lo script**

```php
<?php

require_once 'autoload.php';

eZINI::instance('file.ini')
    ->setVariable('ClusteringSettings', 'FileHandler', 'eZFSFileHandler');

$cli = eZCLI::instance();
$script = eZScript::instance(array('description' => ("Ensure ezcontentobject_tree_path_pattern index exists"),
    'use-session' => false,
    'use-modules' => false,
    'use-extensions' => false));

$script->startup();

$options = $script->getOptions("[host:][port:][user:][password:][database:]",
    "",
    array(
        'host' => "Connect to host database",
        'port' => "Connect to host database port",
        'user' => "User for login to the database",
        'password' => "Password to use when connecting to the database",
        'database' => "Connecting to the database",
    )
);
$script->initialize();

$host = $options['host'];
$port = $options['port'];
$user = $options['user'];
$database = $options['database'];
$password = is_string($options['password']) ? $options['password'] : "";

$connectString = sprintf('pgsql:host=%s;port=%s;dbname=%s;user=%s;password=%s',
    $host,
    $port,
    $database,
    $user,
    $password
);

$indexName = 'ezcontentobject_tree_path_pattern';

try {
    $db = new PDO($connectString, $user, $password);
    // Esplicito: senza, PDOStatement::exec() su un errore SQL ritorna false
    // invece di lanciare, e lo script non se ne accorgerebbe.
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->query(
        "SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass('{$indexName}')"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // PDO ritorna i boolean di Postgres come stringhe 't'/'f', non bool nativi.
    $existsButInvalid = $row && $row['indisvalid'] === 'f';

    if ($existsButInvalid) {
        // Lasciato da una CREATE INDEX CONCURRENTLY interrotta a metà
        // (es. container riavviato mentre la build era in corso): senza
        // questo passaggio, IF NOT EXISTS lo considererebbe "già esistente"
        // e non riproverebbe mai più.
        $cli->warning("$indexName exists but is invalid, dropping before retry");
        $db->exec("DROP INDEX CONCURRENTLY IF EXISTS {$indexName};");
    }

    if (!$row || $existsButInvalid) {
        $db->exec(
            "CREATE INDEX CONCURRENTLY IF NOT EXISTS {$indexName} ON ezcontentobject_tree (path_string varchar_pattern_ops);"
        );
    }

    $script->shutdown();
} catch (PDOException $e) {
    // Non bloccante di proposito: è un'ottimizzazione di query, non un
    // requisito funzionale. Un fallimento qui non deve mai impedire
    // l'avvio del container (vedi Task 3: nessun '|| exit N' sulla chiamata).
    $cli->warning("install_path_pattern_index.php: " . $e->getMessage());
    $script->shutdown();
}
```

- [ ] **Step 3: Verifica sintattica**

```bash
php -l bin/install_path_pattern_index.php
```

Atteso: `No syntax errors detected`.

- [ ] **Step 4: Verifica funzionale sullo stack locale (`sito-comunale-dev`)**

`ocinstaller` è già montato in bind mount in `sito-comunale-dev/docker-compose.override.yml` (`../ocinstaller:/var/www/html/vendor/opencontent/ocinstaller`) — nessun rebuild necessario, il container vede subito il nuovo file.

```bash
cd ../../sito-comunale-dev   # dalla root di ocinstaller
docker compose up -d
docker compose exec app env | grep DatabaseSettings   # per leggere host/port/user/password/database reali
docker compose exec app php vendor/opencontent/ocinstaller/bin/install_path_pattern_index.php \
  --host=<Server> --port=<Port> --user=<User> --password=<Password> --database=<Database>
```

Atteso: nessun errore, uscita 0.

```bash
docker compose exec postgres psql -U openpa -d opencity -c \
  "SELECT indexname, indexdef FROM pg_indexes WHERE indexname = 'ezcontentobject_tree_path_pattern';"
```

Atteso: una riga, `indexdef` contiene `varchar_pattern_ops`.

- [ ] **Step 5: Verifica idempotenza (rieseguire non deve fallire né duplicare)**

```bash
docker compose exec app php vendor/opencontent/ocinstaller/bin/install_path_pattern_index.php \
  --host=<Server> --port=<Port> --user=<User> --password=<Password> --database=<Database>
echo "exit code: $?"
```

Atteso: `exit code: 0` (nessun errore "already exists"), e la query dello Step 4 continua a mostrare **una sola** riga (non duplicata).

- [ ] **Step 6: Verifica del ramo "indice invalido"**

Simulare un indice invalido lasciato da una build interrotta:

```bash
docker compose exec postgres psql -U openpa -d opencity -c \
  "DROP INDEX IF EXISTS ezcontentobject_tree_path_pattern;
   CREATE INDEX ezcontentobject_tree_path_pattern ON ezcontentobject_tree (path_string varchar_pattern_ops);
   UPDATE pg_index SET indisvalid = false WHERE indexrelid = 'ezcontentobject_tree_path_pattern'::regclass;"
```

```bash
docker compose exec app php vendor/opencontent/ocinstaller/bin/install_path_pattern_index.php \
  --host=<Server> --port=<Port> --user=<User> --password=<Password> --database=<Database>
```

Atteso in output: la riga di warning `"... exists but is invalid, dropping before retry"`, poi l'indice ricreato **valido**:

```bash
docker compose exec postgres psql -U openpa -d opencity -c \
  "SELECT indisvalid FROM pg_index WHERE indexrelid = 'ezcontentobject_tree_path_pattern'::regclass;"
```

Atteso: `t` (valido).

- [ ] **Step 7: Verifica del ramo "errore non bloccante"**

Rilanciare lo script con una password sbagliata:

```bash
docker compose exec app php vendor/opencontent/ocinstaller/bin/install_path_pattern_index.php \
  --host=<Server> --port=<Port> --user=<User> --password=password-sbagliata --database=<Database>
echo "exit code: $?"
```

Atteso: un warning stampato con il messaggio dell'errore di autenticazione, **e comunque `exit code: 0`** (non deve mai propagarsi come fallimento bloccante).

- [ ] **Step 8: Commit**

```bash
git add bin/install_path_pattern_index.php
git commit -m "Aggiunge script idempotente e auto-risanante per l'indice pattern-ops su path_string"
```

- [ ] **Step 9: STOP — mostrare il diff e attendere conferma esplicita prima del push**

Nessun push automatico. Mostrare `git diff master..feature/path-pattern-index-autoinstall` e attendere l'ok esplicito prima di `git push`.

---

## Task 2: Aggiornare la dipendenza in `prodotto-sito-comunale`

**Repo:** `prodotto-sito-comunale` (`gitlab.com/opencity-labs/sito-istituzionale/cms`) — eccezione di profilo: si lavora direttamente su `master`, nessun branch richiesto.

**Files:**
- Modify: `composer.json` (nessuna modifica di contenuto: la dipendenza è già `"opencontent/ocinstaller": "dev-master"` — verificare solo che non sia cambiata)
- Modify: `composer.lock` (il commit puntato per `opencontent/ocinstaller` deve avanzare fino a includere il Task 1)

**Interfaces:**
- Richiede: Task 1 già **mergiato su `master` di `ocinstaller`** (non basta il branch — `dev-master` in Composer segue il branch `master`, non un branch arbitrario).

- [ ] **Step 1: Verificare che il merge del Task 1 sia avvenuto**

```bash
cd ocinstaller
git fetch origin
git log origin/master -1 --oneline
```

Atteso: il commit del Task 1 (o un merge che lo include) è presente su `origin/master`.

- [ ] **Step 2: Aggiornare il lock**

```bash
cd ../prodotto-sito-comunale
composer update opencontent/ocinstaller
```

- [ ] **Step 3: Verificare cosa è cambiato nel lock**

```bash
git diff composer.lock
```

Atteso: solo la voce `opencontent/ocinstaller` cambia (`version`/`reference`/`time`), nessun'altra dipendenza tocca — se compare altro, **fermarsi e capire perché** prima di procedere (potrebbe indicare un `composer.json` non allineato o un vincolo di versione che ha smosso altro).

- [ ] **Step 4: Commit (nessun push ancora — vedi Task 3, si committa insieme)**

```bash
git add composer.lock
git commit -m "Aggiorna opencontent/ocinstaller per includere install_path_pattern_index.php"
```

---

## Task 3: Invocazione incondizionata nell'entrypoint

**Repo:** `prodotto-sito-comunale`, stesso commit/branch del Task 2.

**Files:**
- Modify: `docker/php/scripts/install.sh:84-97`

**Interfaces:**
- Consuma: `bin/install_path_pattern_index.php` (Task 1), stesse variabili d'ambiente `EZINI_site__DatabaseSettings__*` già usate dalla riga `install_pgcrypto.php` esistente (riga 89).

- [ ] **Step 1: Modificare `install.sh`**

Il blocco attuale (righe 84-97):

```bash
if [[ -n $EZ_INSTANCE ]]; then
    if [[ -f vendor/bin/ocinstall ]]; then
        if [[ $RUN_INSTALLER == 'true' ]]; then

            echo "[info] ensure pgcrypto is available"
            sudo -E -u $EZ_USER php vendor/opencontent/ocinstaller/bin/install_pgcrypto.php --host=${EZINI_site__DatabaseSettings__Server} --port=${EZINI_site__DatabaseSettings__Port} --user=${EZINI_site__DatabaseSettings__User} --password=${EZINI_site__DatabaseSettings__Password} --database=${EZINI_site__DatabaseSettings__Database}  || exit 2

            echo "[info] run installer on ${EZ_INSTANCE}"
            sudo -E -u $EZ_USER php vendor/bin/ocinstall --allow-root-user -sbackend --embed-dfs-schema --no-interaction --languages=ita-IT,ita-PA ./vendor/opencity-labs/opencity-installer/
        else
            echo "[info] RUN_INSTALLER is set to false (install only base schema)"
            echo "[info] run installer on ${EZ_INSTANCE}"
            sudo -E -u $EZ_USER php vendor/bin/ocinstall --allow-root-user -sbackend --embed-dfs-schema --no-interaction --languages=ita-IT,ita-PA --only-schema ./vendor/opencity-labs/opencity-installer/
        fi
```

diventa (una riga nuova subito dopo `if [[ -f vendor/bin/ocinstall ]]; then`, **fuori** dal blocco `if $RUN_INSTALLER`):

```bash
if [[ -n $EZ_INSTANCE ]]; then
    if [[ -f vendor/bin/ocinstall ]]; then

        echo "[info] ensure ezcontentobject_tree_path_pattern index is available"
        sudo -E -u $EZ_USER php vendor/opencontent/ocinstaller/bin/install_path_pattern_index.php --host=${EZINI_site__DatabaseSettings__Server} --port=${EZINI_site__DatabaseSettings__Port} --user=${EZINI_site__DatabaseSettings__User} --password=${EZINI_site__DatabaseSettings__Password} --database=${EZINI_site__DatabaseSettings__Database}

        if [[ $RUN_INSTALLER == 'true' ]]; then

            echo "[info] ensure pgcrypto is available"
            sudo -E -u $EZ_USER php vendor/opencontent/ocinstaller/bin/install_pgcrypto.php --host=${EZINI_site__DatabaseSettings__Server} --port=${EZINI_site__DatabaseSettings__Port} --user=${EZINI_site__DatabaseSettings__User} --password=${EZINI_site__DatabaseSettings__Password} --database=${EZINI_site__DatabaseSettings__Database}  || exit 2

            echo "[info] run installer on ${EZ_INSTANCE}"
            sudo -E -u $EZ_USER php vendor/bin/ocinstall --allow-root-user -sbackend --embed-dfs-schema --no-interaction --languages=ita-IT,ita-PA ./vendor/opencity-labs/opencity-installer/
        else
            echo "[info] RUN_INSTALLER is set to false (install only base schema)"
            echo "[info] run installer on ${EZ_INSTANCE}"
            sudo -E -u $EZ_USER php vendor/bin/ocinstall --allow-root-user -sbackend --embed-dfs-schema --no-interaction --languages=ita-IT,ita-PA --only-schema ./vendor/opencity-labs/opencity-installer/
        fi
```

Nota deliberata: **nessun `|| exit N`** sulla nuova riga (a differenza di quella di `pgcrypto` appena sotto) — vedi Global Constraints.

- [ ] **Step 2: Validazione sintattica**

```bash
bash -n docker/php/scripts/install.sh
```

Atteso: nessun errore.

- [ ] **Step 3: Verifica manuale del percorso "container già esistente" (`RUN_INSTALLER=false`)**

Non è praticamente testabile end-to-end nello stack locale `sito-comunale-dev` (ha una sua copia separata di `install.sh`, non collegata a questo repo, e va sempre con `RUN_INSTALLER=true` di default per un sito di sviluppo da zero). La verifica reale che la riga giri anche quando `RUN_INSTALLER=false` è: lettura del file risultante — confermare a occhio che la nuova riga sia effettivamente fuori dall'`if/else` che segue, non dentro nessuno dei due rami.

- [ ] **Step 4: Commit e push (con conferma)**

```bash
git add docker/php/scripts/install.sh
git commit -m "Esegue install_path_pattern_index.php ad ogni avvio, indipendentemente da RUN_INSTALLER"
```

Mostrare il diff completo (`composer.lock` del Task 2 + questo commit) e attendere conferma esplicita prima di `git push` — resta valida la regola di non pushare senza conferma di aver testato.

---

## Task 4: Lancio manuale batch su SaaS

**Repo:** `saasopenpa-distribution` (repo del codice applicativo — verificare con chi gestisce il deploy SaaS se il `composer update` va fatto anche/solo su `saasopenpa-distribution-prod`, che ha un `composer.json` quasi identico: le due repo non sono state chiarite del tutto in questa sessione, vedi nota sotto).

**Files:**
- Modify: `composer.lock` (stesso motivo del Task 2: `dev-master` non avanza da solo)
- Create: `cron/scripts/run_path_pattern_index.sh` — script di lancio batch, **non** aggiunto a nessun crontab (Global Constraints: resta manuale)

**Interfaces:**
- Richiede: Task 1 mergiato su `origin/master` di `ocinstaller` (stesso vincolo del Task 2).
- Consuma: `bin/install_path_pattern_index.php` (Task 1), letto via `vendor/opencontent/ocinstaller/`.
- Consuma: `html/settings/siteaccess/<tenant>_frontend/site.ini.append.php` di ogni tenant (sezione `[DatabaseSettings]`: `Server`, `Port`, `User`, `Password`) e `cron/elenco_istanze_per_cron` (lista istanze attive, stesso file già usato da `ezcron`).

- [ ] **Step 1: Aggiornare il lock (sul server, non in locale — vedi Step 3)**

Va fatto sul server dove gira il deploy SaaS (segue lo stesso pattern già documentato in `doc/CREA_NUOVA_ISTANZA.md`: `git pull; composer install`), non richiede una modifica di `composer.json` (dipendenza già `dev-master`).

- [ ] **Step 2: Scrivere lo script di lancio batch**

```bash
#!/usr/bin/env bash
# Lancio MANUALE, ops-triggered — non schedulato, non wired a nessun deploy.
# Applica install_path_pattern_index.php a tutte le istanze della lista data
# (default: tutte le istanze attive).
#
# Uso:
#   ./run_path_pattern_index.sh                              # tutte le istanze
#   ./run_path_pattern_index.sh cron/elenco_istanze_import    # solo una lista specifica
#   ./run_path_pattern_index.sh --one <istanza>                # una sola istanza

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."   # porta in html/, come gli altri script cron

SCRIPT_PATH="vendor/opencontent/ocinstaller/bin/install_path_pattern_index.php"
LOG_FILE="var/log/cron/run_path_pattern_index.log"

if [[ ! -f "$SCRIPT_PATH" ]]; then
  echo "Errore: $SCRIPT_PATH non trovato — composer update opencontent/ocinstaller non ancora fatto?" >&2
  exit 1
fi

run_for_instance() {
  local instance="$1"
  local ini="settings/siteaccess/${instance}_frontend/site.ini.append.php"

  if [[ ! -f "$ini" ]]; then
    echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) instance=$instance status=skip reason=ini-not-found" | tee -a "$LOG_FILE"
    return
  fi

  local server port user password database
  server=$(awk -F= '/^Server=/{print $2; exit}' "$ini")
  port=$(awk -F= '/^Port=/{print $2; exit}' "$ini")
  user=$(awk -F= '/^User=/{print $2; exit}' "$ini")
  password=$(awk -F= '/^Password=/{print $2; exit}' "$ini")
  database=$(awk -F= '/^Database=/{print $2; exit}' "$ini")

  php "$SCRIPT_PATH" \
    --host="${server:-db.saasopenpa-astratto}" \
    --port="${port:-5432}" \
    --user="$user" \
    --password="$password" \
    --database="${database:-$instance}" \
    && echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) instance=$instance status=ok" | tee -a "$LOG_FILE" \
    || echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) instance=$instance status=error" | tee -a "$LOG_FILE"
}

mkdir -p "$(dirname "$LOG_FILE")"

if [[ "${1:-}" == "--one" ]]; then
  run_for_instance "$2"
else
  LIST="${1:-cron/elenco_istanze_per_cron}"
  while read -r instance; do
    [[ -z "$instance" ]] && continue
    run_for_instance "$instance"
  done < "$LIST"
fi
```

Nota: lo script del Task 1 è già non-bloccante e ritorna sempre `exit 0` (vedi Global Constraints del Task 1) — il ramo `status=error` qui sopra in pratica non dovrebbe mai scattare per un errore *interno* allo script PHP, ma resta come rete di sicurezza per un fallimento a monte (es. `php` non trovato, permessi).

- [ ] **Step 3: Validazione sintattica**

```bash
bash -n cron/scripts/run_path_pattern_index.sh
```

- [ ] **Step 4: Prova su UNA istanza reale, non su tutta la lista**

```bash
./cron/scripts/run_path_pattern_index.sh --one <un-tenant-di-prova>
tail -5 var/log/cron/run_path_pattern_index.log
```

Atteso: riga `status=ok`.

```bash
psql --host=db.saasopenpa-astratto --username=openpa --dbname=<un-tenant-di-prova> -c \
  "SELECT indexname FROM pg_indexes WHERE indexname = 'ezcontentobject_tree_path_pattern';"
```

Atteso: una riga.

- [ ] **Step 5: STOP — conferma esplicita prima del lancio su tutta la lista**

Esporre chiaramente: quante istanze in `cron/elenco_istanze_per_cron` (`wc -l`), che tutte richiederanno una `CREATE INDEX CONCURRENTLY` (I/O sul cluster condiviso, una tenant alla volta perché lo script non parallelizza), tempo stimato. Attendere conferma esplicita prima di lanciare senza `--one`.

- [ ] **Step 6: Lancio completo**

```bash
./cron/scripts/run_path_pattern_index.sh 2>&1 | tee -a var/log/cron/run_path_pattern_index_full_run.log
grep -c 'status=ok' var/log/cron/run_path_pattern_index.log
grep 'status=skip\|status=error' var/log/cron/run_path_pattern_index.log
```

Controllare che le righe `skip`/`error` (se presenti) abbiano una spiegazione chiara (istanza dismessa, ini mancante, ecc.) prima di considerare il rollout completo.

- [ ] **Step 7: Commit**

```bash
git add composer.lock cron/scripts/run_path_pattern_index.sh
git commit -m "Aggiunge script di lancio batch manuale per l'indice pattern-ops su path_string"
```

Mostrare il diff e attendere conferma esplicita prima del push (nessuna eccezione di profilo nota per questo repo — trattarlo con la regola generale finché non confermato diversamente).

---

## Nota per la review (Luca)

- **Tempistica di copertura sui ~500 tenant esistenti**: questo meccanismo non è "istantaneo". Un tenant già attivo lo riceve al **prossimo riavvio/redeploy del suo container** (rolling update dell'immagine, restart per altre ragioni, ecc.), non appena l'immagine `cms` viene rebuildata con questo commit. Se serve una copertura garantita entro una data, va comunque pianificato un redeploy della flotta — ma è un redeploy "normale" (probabilmente già previsto per altre ragioni), non un'operazione dedicata sito-per-sito come nella versione precedente di questo piano.
- **Perché non usare `type: sql` dentro `installer.yml`, un modulo `ocinstaller`, o `--only-step`**: analizzato e scartato — con `RUN_INSTALLER=false` (stato di tutti i siti già live) gli step vengono saltati del tutto (`bin/ocinstall` riga ~387, `"Data installation skipped"`), quindi non risolvono il rollout sui siti esistenti senza un trigger manuale per tenant, il problema che stavamo cercando di evitare.
- **Rischio noto e accettato**: se il container viene riavviato *mentre* la build `CONCURRENTLY` di un tenant molto grande è ancora in corso, il tentativo successivo lo gestisce (Task 1, Step 6 — drop-and-retry sull'indice invalido) — non richiede intervento manuale.
- **Nessuna azione su database di produzione è stata eseguita per scrivere questo piano** — la verifica empirica menzionata nella sezione Spec era stata fatta con letture (`EXPLAIN ANALYZE`) in una sessione precedente, non ripetuta qui.
- **SaaS resta manuale per decisione esplicita**, non per limite tecnico: sarebbe stato possibile agganciare lo stesso script a un cron esistente (pattern `ezcron`, già usato per girare su tutte le istanze in parallelo) per renderlo automatico come su Boat — scartato deliberatamente a favore di un lancio batch controllato da un ops (Task 4).
- **Da chiarire con chi gestisce il deploy SaaS**: la relazione esatta tra `saasopenpa-distribution` e `saasopenpa-distribution-prod` (composer.json quasi identici in entrambe le repo locali) — il Task 4 assume che `saasopenpa-distribution` sia quella da aggiornare, coerente con quanto dice il suo stesso `CLAUDE.md` ("il codice applicativo è nel repo separato saasopenpa-distribution"), ma non l'ho verificato oltre la lettura di quel file.
