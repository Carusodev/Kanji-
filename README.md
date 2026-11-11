# Studio di giapponese

## Salvataggio online su hosting PHP/MySQL

L'app conserva sempre una copia locale dei progressi e, quando viene pubblicata su un hosting con PHP 8.0+ e PDO MySQL, ne sincronizza una copia tramite `api.php` e MySQL. Vengono salvati punti, kanji sbloccati, kanji da ripassare, preferenza dei significati nel quiz e gli ultimi 50 risultati dei quiz kanji, hiragana e katakana.

Il salvataggio è anonimo e legato al browser/dispositivo: non richiede account, ma non permette di recuperare i dati da un altro dispositivo o dopo aver cancellato i dati del browser. Per riconoscere quel browser viene creato un identificativo casuale; il server ne salva solo l'hash.

### Pubblicazione

1. Crea/attiva un database MySQL dal pannello dell'hosting e annota host, nome database, utente e password.
2. Copia `config.example.php` in `config.php`; inserisci i dati del database nel file copiato. Non condividere né pubblicare il file con credenziali reali nel repository.
3. Carica `index.html`, `api.php` e `config.php` nella stessa cartella del sito. Non è necessario creare la tabella a mano: l'API la crea al primo accesso (l'utente MySQL deve poter creare tabelle).
4. Apri il sito tramite il suo indirizzo `https://...`, non aprendo `index.html` come file. Alla prima visita i progressi locali vengono copiati nel database.

Se l'API o il database non sono disponibili, l'app mostra lo stato della sincronizzazione e continua a conservare i progressi nel browser. Quando la connessione torna online o l'utente salva altri progressi, riprova a sincronizzarli.

### Nota sui risultati

Il server valida formato e dimensioni, ma i risultati sono inseriti dal browser dell'utente: non sono attestati né protetti da manipolazioni. Questa soluzione è pensata per salvare progressi personali in una web app didattica, non per classifiche o premi.
