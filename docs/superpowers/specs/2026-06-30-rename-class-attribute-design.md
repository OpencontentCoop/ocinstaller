# Design: step `rename_class_attribute` per ocinstaller

**Data**: 2026-06-30  
**Stato**: analisi corner case completata — in attesa di risposta su domanda aperta

---

## Problema

In ocinstaller, il matching degli attributi di classe avviene per identifier (`occlasstools.php:543`).
Se si rinomina un identifier nel YAML e si rilancia l'installer, vengono creati un nuovo attributo (con il nome nuovo) e il vecchio rimane come "extra" — i dati degli oggetti esistenti restano legati al vecchio attributo e vanno persi ai fini del modello aggiornato.

**Caso concreto**: l'attributo `ife_event` nella classe `public_service` ha il nome sbagliato e va rinominato.

---

## Analisi tecnica del DB

`ezcontentobject_attribute.contentclassattribute_id` punta all'**ID numerico** di `ezcontentclass_attribute.id`, non all'identifier testuale. Questo significa che un semplice:

```sql
UPDATE ezcontentclass_attribute
SET identifier = 'new_name'
WHERE identifier = 'old_name'
  AND contentclass_id = :class_id
```

è sufficiente per rinominare l'attributo **senza perdere i dati** degli oggetti esistenti.

Non ci sono FK constraint sull'identifier — solo un btree index su `(id, version)` e uno su `(contentclass_id)`.

---

## Corner case trovati

### CC1 — version=1 (draft di classe)
`ezcontentclass_attribute` ha una colonna `version` (0=pubblicata, 1=draft in edit).
Se qualcuno sta editando la classe in admin contemporaneamente, esiste una riga version=1
che deve essere aggiornata insieme alla version=0.
Il DB locale di sviluppo attualmente ha 0 righe version=1, ma in produzione può succedere.
La query deve aggiornare **entrambe** le versioni (WHERE senza filtro su version).

### CC2 — Cache PHP da invalidare
eZ Publish mantiene due livelli di cache sull'hash `class/attribute_identifier → id`:
- **Cache su disco**: `var/cache/classattridentifiers_<md5db>.php` (clustering: `classattridentifiers`)
- **Cache in memoria**: static `$identifierHash` in `eZContentClassAttribute`

Dopo il rename la cache è stale. Invalidazione via:
```php
$handler = eZExpiryHandler::instance();
$handler->setTimestamp('class-identifier-cache', -1);
```
Stesso pattern già usato in `ContentClass.php:66`.

### CC3 — Search index (Solr + Meilisearch)
I field name in Solr e Meilisearch sono basati sull'identifier dell'attributo.
Dopo il rename, i documenti già indicizzati hanno il vecchio field name e la ricerca
per il nuovo attributo non funziona finché non si reindicizza.
Lo step deve **loggare un warning esplicito**; non deve triggare il reindex da solo
(ci sono già step `reindex` per quello, da aggiungere in `installer.yml` dopo questo step).

### CC4 — Hardcoded string references nel codice (fuori scope)
In `occsvimport/ocm_public_service.php` (righe 50, 205, 296, 482) l'identifier `ife_event`
è hardcoded come stringa. Questi si rompono silenziosamente dopo il rename — nessun errore
PHP, semplicemente l'attributo sbagliato viene letto/scritto.
**Fuori scope per lo step** — il dev deve aggiornare il codice manualmente.

### CC5 — FieldMap ocwebhookserver (fuori scope)
`ocwebhookkafkafieldmap.php:128` mappa `'ife_event' → 'life_events'` nel payload Kafka.
Dopo rename deve essere aggiornato manualmente.
**Fuori scope per lo step** — il dev deve aggiornare il codice.

### CC6 — YAML installer da aggiornare (warning)
Dopo il rename nel DB, il file `classes/public_service.yml` contiene ancora il vecchio
identifier. Al prossimo run dell'installer lo step `class` vede il vecchio identifier come
attributo mancante e ne crea uno nuovo duplicato.
Lo step deve **loggare un warning esplicito**: aggiornare il YAML della classe prima
di rieseguire lo step `class` per quella classe.

### CC7 — Idempotenza (re-run safety)
Se l'installer viene rieseguito dopo un rename già completato, lo step non deve esplodere.
Logica:
- `old` non esiste + `new` già esiste → **skip silenzioso** (già fatto)
- `old` non esiste + `new` non esiste → **errore esplicito** (stato inatteso)
- `old` esiste + `new` non esiste → **esegui rename** (caso normale)
- `old` esiste + `new` esiste già → **errore** (conflitto)

### CC8 — Unicità identifier nella classe
Il nuovo identifier non deve già esistere nella stessa classe.
Verificare prima di eseguire l'UPDATE (vedi CC7, caso "old esiste + new esiste già").

### CC9 — Scope della query per contentclass_id
L'identifier è unico per classe ma lo stesso identifier può esistere in classi diverse.
La query deve **sempre** filtrare per `contentclass_id` — mai rinominare per identifier globale.

### CC10 — Kafka schema (fuori scope, nota per release)
Da `CLAUDE.md` del CMS: ogni rename di attributo impatta il payload Kafka e gli schemi
su `schemas.opencityitalia.it` (repo `product`). Questo è fuori scope per lo step ma
va tenuto presente nel processo di release.

---

## Utilizzo previsto (YAML)

```yaml
- type: rename_class_attribute
  class: public_service
  from: ife_event
  to: life_events
```

Da inserire in `installer.yml` **prima** dello step `class` che aggiorna il YAML della classe,
e **seguito** da uno step `reindex` se si usa la ricerca.

---

## Domanda aperta (da rispondere prima di procedere)

Lo step deve limitarsi al **solo rename sul DB** (e lasciare al dev il reindex, l'aggiornamento
del YAML e del codice), oppure deve includere un **check pre-esecuzione** che avvisa
esplicitamente di tutti i side-effect trovati (YAML da aggiornare, reindex necessario, ecc.)?
