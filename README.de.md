# JUST

**J**ust **U**pload, **S**elect, **T**est (Itembank hochladen, Items adaptiv auswählen, testen): eine Webanwendung für computerisierte adaptive Tests (CAT) auf Grundlage der
Item-Response-Theorie. Sie braucht nur PHP und eine MySQL-/MariaDB-Datenbank
und läuft daher auf gewöhnlichem Shared Hosting. Composer, Node oder Docker
sind nicht nötig.

English version: `README.md`

- **Modelle:** 1PL (Rasch), 2PL, 3PL, Graded Response Model (GRM)
- **Antwortformate:** richtig/falsch, Mehrfachwahl, Likert
- **Schätzung:** EAP mit N(0, 1)-Prior auf 901 Stützstellen für θ von −4,5 bis 4,5
- **Itemauswahl:** maximale Fisher-Information, posterior-gewichtete Information oder minimale erwartete Posterior-Varianz, wahlweise randomesque
- **Abbruchregel:** eine Höchstzahl an Items oder ein SEM bis zu einem Grenzwert, je nachdem, was zuerst eintritt
- **Teilnehmende:** keine Anmeldung, ein Link pro Test (`/t/name`)
- **Anbindung an Befragungen:** ein pseudonymes `?ref=`-Token kommt herein, eine optionale Rücksprungadresse führt zurück zur Befragung. Testwerte verlassen das System nie über URLs.
- **Konten:** ein Administrator und beliebig viele Projektleitungen, die jeweils nur ihre eigenen Tests, Itembanken und Ergebnisse sehen
- **Sprachen:** die gesamte Anwendung gibt es auf Deutsch, Englisch, Französisch, Spanisch, Japanisch und Chinesisch
- **Werkzeuge:** Neuberechnung hochgeladener Antworten und Exporte im Long- und Wide-Format

## 1. Installation

1. Legen Sie im Kundenbereich Ihres Hosters eine leere MySQL- oder MariaDB-Datenbank an und notieren Sie Host, Datenbankname, Benutzer und Passwort.
2. Laden Sie die Dateien herunter (auf GitHub: **Code → Download ZIP**) und entpacken Sie sie. Laden Sie den ganzen Ordner per FTP hoch, zum Beispiel nach `https://example.de/cat/`. Laden Sie auch die `.htaccess`-Dateien hoch (es sind versteckte Dateien).
3. Öffnen Sie `https://example.de/cat/install.php`.
4. Geben Sie die Datenbankdaten ein und klicken Sie auf **Verbindung testen**. Die Seite zeigt, ob die Verbindung funktioniert und ob der Benutzer Tabellen anlegen darf.
5. Geben Sie Name, Benutzername und Passwort (mindestens 10 Zeichen) für das Administratorkonto ein und klicken Sie auf **Installieren**.
6. Klicken Sie auf der Erfolgsseite auf **install.php löschen** oder löschen Sie die Datei per FTP.
7. Melden Sie sich unter `https://example.de/cat/admin/` an.

Kann das Installationsprogramm `lib/config.php` nicht schreiben, zeigt es den Inhalt der Datei an. Legen Sie die Datei dann per FTP an und laden Sie die Seite neu.

**Voraussetzungen:** PHP 8.1 oder neuer mit `pdo_mysql` sowie MySQL 5.7+ oder MariaDB 10.3+. HTTPS wird dringend empfohlen. Apache mit `mod_rewrite` ermöglicht die kurzen Links `/t/name`; ohne dieses Modul entfernen Sie bei der Installation das Häkchen bei „Kurze Testlinks“, die Links lauten dann `t.php?slug=name`.

**Aktualisieren:** Laden Sie die neuen Dateien über die alten, behalten Sie aber `lib/config.php` und laden Sie `install.php` nicht erneut hoch. Die Datenbank wird beim nächsten Aufruf der Anmeldeseite automatisch angepasst.

**Aktualisieren von Version 1.1 oder älter:** Diese Versionen hatten ein einzelnes Admin-Passwort. Melden Sie sich nach der Aktualisierung mit dem Benutzernamen `admin` und Ihrem bisherigen Passwort an. Alle vorhandenen Tests und Itembanken gehören diesem Konto, die Beispiel-Itembanken sind als freigegeben markiert. Name und Benutzername lassen sich unter *Konten* ändern.

## 2. Itembanken

Eine Itembank wird als CSV-Datei mit einer Zeile pro Item hochgeladen. Verwenden Sie in Excel **Speichern unter → CSV UTF-8**. Semikolons und Dezimalkommas aus deutschem Excel werden automatisch erkannt, ebenso Dateien im einfachen „CSV“-Format (Windows-1252).

| Spalte | Nötig für | Inhalt |
|---|---|---|
| `item_id` | alle | Eindeutiger Itemname, z. B. `K01`. Auch Spaltenname im Wide-Export. |
| `question_text` | alle | Itemtext (reiner Text) |
| `response_type` | alle | `dichotomous`, `multiple_choice` oder `likert` |
| `model` | alle | `1PL`, `2PL`, `3PL` (mit dichotomous oder multiple_choice) oder `GRM` (mit likert) |
| `a` | 2PL, 3PL, GRM | Trennschärfe, größer als 0. Bei 1PL optional (Standard 1 = Rasch). |
| `b` | 1PL, 2PL, 3PL | Schwierigkeit |
| `c` | 3PL | Untere Asymptote (Rateparameter), mindestens 0 und kleiner als 1 |
| `b1`, `b2`, … `bk` | GRM | Schwellen, eine pro Spalte, streng aufsteigend. k Schwellen ergeben k + 1 Antwortkategorien. |
| `option_1`, `option_2`, … | Mehrfachwahl | Antworttexte. Bei dichotomen Items optional (Standard ja/nein), bei Likert-Items optional (eine Beschriftung pro Kategorie). |
| `correct_option` | Mehrfachwahl | Nummer der Option, die mit 1 bewertet wird. Bei dichotomen Items optional, Standard 1. |
| `likert_min_label`, `likert_max_label` | – | Beschriftungen der beiden Skalenenden |
| `reverse` | – | `1` polt ein Likert-Item um |

**Parametermetrik:** logistische Metrik ohne D = 1,7, wie sie `mirt::coef(model, IRTpars = TRUE, simplify = TRUE)` ausgibt. Liegen Ihre `a`-Parameter in der Normalogive-Metrik vor, multiplizieren Sie sie mit 1,7.

**Bewertung:** Antworten auf Richtig/falsch- und Mehrfachwahl-Items werden mit `correct_option` verglichen (Wert 0 oder 1). Bei Likert-Items entspricht die k-te Antwortoption der GRM-Kategorie k − 1, bei `reverse` = 1 umgepolt.

Eine Datei wird nur importiert, wenn alle Zeilen gültig sind; andernfalls listet die Seite jedes Problem mit Zeilennummer auf. Items lassen sich im Adminbereich nicht bearbeiten: Korrigieren Sie die CSV-Datei und laden Sie sie als neue Itembank hoch.

Die Seite einer Itembank zeigt ihre **Testinformationskurve**: in welchem θ-Bereich die Itembank genau misst, mit Hilfslinien für die Information, die für ein SEM von 0,30 bzw. 0,40 nötig ist.

**Beispiele:** Unter *Item banks → Example item banks* fügen Sie mit einem Klick drei fertige Itembanken hinzu: Taylor Swift fandom (20 Mehrfachwahl-Items, 3PL, englisch), Denkaufgaben (20 Items, deutsch) und Selbstorganisation (15 Likert-Items, GRM, deutsch). Die Parameter sind ausgedacht, nicht kalibriert. Die CSV-Dateien liegen im Ordner `examples`.

## 3. Tests

**Tests → Neuer Test**

- **Link-Name** ergibt die Adresse `/t/link-name`.
- **Item bank:** lässt sich nicht mehr ändern, sobald Sitzungen existieren.
- **Sprache** der Teilnehmendenseiten: eine der sechs Sprachen. Die festen Texte stehen in `lib/texts.php`.
- **Introduction:** reiner Text, der vor der ersten Frage erscheint.
- **Abbruchregel:** eine Höchstzahl an Items und/oder ein SEM-Grenzwert. Als grobe Orientierung entspricht ein SEM von 0,30 einer Reliabilität von etwa .91, ein SEM von 0,40 etwa .84. Mit 0 schalten Sie die SEM-Regel ab.
- **Itemauswahl:** siehe Abschnitt 4.
- **Rücksprungadresse** und **Start nur mit Referenz-Token:** siehe Abschnitt 6.
- **Active:** Neue Tests sind inaktiv, bis Sie dieses Häkchen setzen.

Während des Tests enthält ein Sitzungscookie ein zufälliges Token; der gesamte Fortschritt liegt in der Datenbank. Wird die Seite neu geladen, erscheint dieselbe Frage erneut. Schließt eine Person den Browser, bleibt die Sitzung unvollständig; nach 120 Minuten ohne Antwort wird sie als *abandoned* angezeigt (Einstellung `abandon_after_minutes` in `lib/config.php`).

## 4. Itemauswahl

Nach jeder Antwort wird θ neu geschätzt und das nächste Item aus den noch nicht vorgegebenen gewählt. Zwei Einstellungen pro Test bestimmen, wie:

**Kriterium**

- **Maximale Fisher-Information:** das Item mit der höchsten Information am aktuellen θ-Schätzwert. Das ist die klassische Wahl, allerdings ist die Schätzung zu Beginn eines Tests noch ungenau.
- **Maximale posterior-gewichtete Information:** die Iteminformation, gemittelt über die gesamte Posterior-Verteilung von θ. Damit wird die Unsicherheit der Schätzung berücksichtigt.
- **Minimale erwartete Posterior-Varianz:** das Item, das die Posterior-Varianz und damit das SEM voraussichtlich am stärksten senkt. Es passt unmittelbar zur SEM-Abbruchregel.

Die beiden bayesianischen Kriterien bringen vor allem bei kurzen Tests Vorteile. Bei längeren Tests liefern alle drei meist sehr ähnliche Ergebnisse.

**Randomesque k:** Das nächste Item wird zufällig aus den k besten Items nach dem Kriterium gezogen, auch das erste. Mit k = 1 sehen alle, die gleich antworten, dieselben Items in derselben Reihenfolge, und das erste Item ist für alle gleich. Werte von 3 bis 5 verteilen die Items über die Itembank (weniger Überbeanspruchung einzelner Items, weniger weitergegebene Lösungen) bei geringem Verlust an Genauigkeit.

Die Einstellungen werden beim Start jeder Sitzung gespeichert. Änderungen gelten nur für danach gestartete Sitzungen; die Exporte enthalten für jede Sitzung die verwendeten Einstellungen.

## 5. Konten, Rollen und Sprachen

Es gibt zwei Rollen.

| | Administrator | Projektleitung |
|---|---|---|
| Itembanken hochladen, Tests anlegen | ja | ja |
| Tests, Sitzungen und Exporte | alle | nur die eigenen Tests |
| Itembanken | alle | die eigenen und die freigegebenen (nur lesend) |
| Hochgeladene Antworten neu berechnen | mit jeder Itembank | mit eigenen und freigegebenen Itembanken |
| Konten anlegen und verwalten | ja | nein |

**Projektleitung anlegen:** Unter *Konten* trägt der Administrator Name, Benutzername und ein Startpasswort ein und gibt Benutzername und Startpasswort an die Person weiter. Bei der ersten Anmeldung muss sie ein eigenes Passwort wählen. Die Anmeldung funktioniert mit dem Benutzernamen oder, falls eingetragen, mit der E-Mail-Adresse. Die Anwendung verschickt keine E-Mails; wer sein Passwort vergisst, erhält vom Administrator unter *Konten* ein neues Startpasswort.

**Freigegebene Itembanken:** Der Administrator kann in den Einstellungen einer Itembank das Häkchen „Freigegeben“ setzen. Alle Projektleitungen können sie dann ansehen und in Tests verwenden, aber nicht ändern oder löschen. Die drei Beispiel-Itembanken sind von Anfang an freigegeben.

**Ausscheiden aus dem Projekt:** Ein Konto lässt sich deaktivieren (die Person kann sich nicht mehr anmelden, alles andere bleibt) oder löschen, nachdem seine Tests und Itembanken an ein anderes Konto übertragen wurden. Die Ergebnisse bleiben bei den Tests.

**Übersicht:** Ein Klick auf „CAT-Verwaltung“ oben links öffnet eine Übersicht über Tests, Itembanken und die letzten Sitzungen. Administratoren sehen zusätzlich alle Konten, Projektleitungen nur ihr eigenes Material.

**Sprachen:** Adminbereich und Installationsprogramm richten sich zunächst nach der Browsersprache. Jede Person kann die Sprache im Menü unten links umstellen; die Wahl wird mit dem Konto gespeichert. Die Sprache der Seiten für Teilnehmende wird pro Test eingestellt. Verfügbar sind Deutsch, Englisch, Französisch, Spanisch, Japanisch und Chinesisch (vereinfacht). Diesen Hilfetext gibt es nur auf Deutsch und Englisch. Die Übersetzungen wurden nicht von Muttersprachlern geprüft; die Texte stehen in `lib/lang/*.json` (Adminbereich) und `lib/texts.php` (Teilnehmende) und lassen sich dort korrigieren.

## 6. Anbindung an LimeSurvey

### Von LimeSurvey zum Test

Setzen Sie die **End-URL** der Umfrage (oder einen Link in einer Textfrage) auf:

```
https://example.de/cat/t/mein-test?ref={TOKEN}
```

Aktivieren Sie in den Präsentationseinstellungen „URL automatisch laden, wenn die Umfrage beendet ist“. LimeSurvey ersetzt `{TOKEN}` durch den Zugangsschlüssel der Person, und der Test speichert ihn als `reference_token`. Ohne Teilnehmendenliste können Sie stattdessen die Antwort-ID verwenden: `?ref={SAVEDID}`.

> Aktivieren Sie in dieser Umfrage nicht „Anonymisierte Antworten“. Sonst speichert LimeSurvey den Zugangsschlüssel nicht zu den Antworten, und die Daten lassen sich nicht zusammenführen.

Das Referenz-Token darf Buchstaben, Ziffern und `_ - . ~` enthalten, höchstens 128 Zeichen.

### Vom Test zurück zu LimeSurvey (optional)

Tragen Sie in den Testeinstellungen eine **Rücksprungadresse** ein, zum Beispiel eine zweite Umfrage:

```
https://umfrage.example.de/index.php/654321?token={ref}&lang=de
```

`{ref}` wird durch das Referenz-Token ersetzt, und `cat_complete=1` wird angehängt. **Zurück geht nur das Token, niemals θ oder SEM.** Nutzt die zweite Umfrage eine Teilnehmendenliste, importieren Sie dort dieselben Zugangsschlüssel.

### Daten zusammenführen

Laden Sie den Wide-Export herunter (Abschnitt 7) und führen Sie die Daten in R zusammen:

```r
cat  <- read.csv("cat_mein-test_wide_2026-11-15.csv")
lime <- read.csv("limesurvey_ergebnisse.csv")
dat  <- merge(lime, cat, by.x = "token", by.y = "reference_token", all.x = TRUE)
```

Startet jemand den Test zweimal (etwa in einem zweiten Browser), enthält der Export zwei Sitzungen mit demselben `reference_token`. Prüfen Sie das vor dem Zusammenführen und behalten Sie zum Beispiel die erste abgeschlossene Sitzung.

## 7. Export

**Export**

| Format | Inhalt |
|---|---|
| **Wide** | Eine Zeile pro Sitzung: Sitzungs-ID, Referenz-Token, Test, Status, Beginn und Ende, Dauer, Itemzahl, θ, SEM, Abbruchgrund, Auswahleinstellungen, danach eine Spalte pro Item mit der bewerteten Antwort (leer = nicht vorgegeben) |
| **Long** | Eine Zeile pro Antwort: Position, Item, `raw_response` (angezeigte Optionsnummer), `scored_response` (vom Modell verwendeter Wert), θ und SEM nach dieser Antwort, finales θ und SEM, Auswahleinstellungen |
| **Item parameters** | Die Itembank des Tests als Codebuch |

Dateiformate: **R/SPSS/Python** (Komma, Dezimalpunkt) oder **deutsches Excel** (Semikolon, Dezimalkomma, UTF-8-BOM). Zeitangaben in UTC.

## 8. Neuberechnung und Abgleich mit R

**Neuberechnung** berechnet θ (EAP) und SE pro Person aus Antworten, die Sie im Long-Format hochladen, mit derselben Berechnung wie im echten Test. Es wird nichts gespeichert. Die Ergebnisse erscheinen auf der Seite oder als CSV-Download.

- **Spalten:** eine Personenspalte (`person_id`, `session_id`, `person`, …), `item_id` und eine Antwortspalte.
- **Kodierung:** entweder bewertete Werte (0/1 bzw. GRM-Kategorien 0 bis K) oder die angezeigten Optionsnummern (1 bis n), die dann mit `correct_option` und `reverse` bewertet werden.
- **Fehlende Antworten:** leere Zellen und `NA` gelten als nicht beantwortet.
- Den Long-Export dieser Anwendung können Sie unverändert hochladen. Enthält die Datei `final_theta`, zeigt die Seite zusätzlich die Abweichung zwischen neu berechnetem θ und dem θ in der Datei.

**In R:** `examples/score_with_mirt.R` liest den Export *Item parameters* und eine Long-Datei und berechnet θ und SE zweimal: mit **mirt** (`fscores(method = "EAP", quadpts = 901, theta_lim = c(-4.5, 4.5))` auf einem Modell mit festen Parametern) und mit einer kurzen **Base-R**-EAP-Berechnung, die nicht von mirt abhängt. Anschließend vergleicht das Skript beides mit den PHP-Ergebnissen. Umrechnung in die Slope-Intercept-Form von mirt: a1 = a, d = −a·b, g = c, bei GRM-Items d_k = −a·b_k. Das Skript gibt `coef(mod, IRTpars = TRUE)` aus, damit Sie prüfen können, dass die Parameter unverändert angekommen sind.

## 9. Datenschutz

**Was die Anwendung speichert:** die Antworten, θ und SEM nach jeder Antwort, Zeitstempel, die Auswahleinstellungen, das optionale Referenz-Token sowie einen SHA-256-Hash des Sitzungstokens. Das Token selbst existiert nur im Cookie der teilnehmenden Person.

**Was sie nicht speichert:** IP-Adressen, Browserangaben, Namen oder E-Mail-Adressen.

**Cookie:** ein Sitzungscookie pro Test (`cat_t{id}`, HttpOnly, SameSite=Lax, wird beim Schließen des Browsers gelöscht). Es ist für die Durchführung des Tests technisch notwendig, ein Einwilligungsbanner ist daher nicht erforderlich (§ 25 Abs. 2 Nr. 2 TDDDG).

**Keine Dritten:** Die Schriften sind Teil der Anwendung. Es wird nichts von anderen Servern geladen (kein CDN, keine Analyse), und `Referrer-Policy: no-referrer` verhindert, dass Adressen weitergegeben werden. Nach dem Start wird das `?ref=`-Token aus der Adresszeile und dem Browserverlauf entfernt.

**Testwerte bleiben im System:** Sie sind nur über den passwortgeschützten Export im Adminbereich zugänglich, nie in URLs.

**Hosting:** Auf Shared Hosting lassen sich die Webserver-Logs meist nicht konfigurieren. Die Zugriffsprotokolle des Hosters enthalten daher den ersten Seitenaufruf mit `?ref=TOKEN`. Halten Sie in Ihrem Datenschutzkonzept fest: den Auftragsverarbeitungsvertrag (AV-Vertrag) mit dem Hoster, wie lange der Hoster Logs aufbewahrt und ob er IP-Adressen anonymisiert. Nutzen Sie einen Hoster mit Servern in der EU.

**Löschung:** Suchen Sie eine Sitzung unter *Sitzungen* über ihr Referenz-Token und wählen Sie *Details → Delete*. Alle Sitzungen eines Tests lassen sich in den Testeinstellungen löschen.

**Pseudonymität:** Die Testdaten sind nur über das Token mit der Befragung verknüpft; das Zusammenführen geschieht auf Ihrem Auswertungsrechner.

## 10. Sicherheit

- Löschen Sie `install.php` nach der Installation.
- `lib/` (einschließlich `config.php` mit dem Datenbankpasswort) wird durch `lib/.htaccess` gesperrt. Auf Servern ohne `.htaccess`-Unterstützung (nginx) stellen Sie sicher, dass `/lib/` nicht ausgeliefert wird; `config.php` gibt auch bei direktem Aufruf nichts aus.
- Passwörter werden als bcrypt-Hash gespeichert. Jede Person ändert ihr eigenes unter *Passwort ändern*. Vom Administrator gesetzte Startpasswörter müssen bei der ersten Anmeldung ersetzt werden.
- Projektleitungen sind auf dem Server voneinander getrennt: Wer einen Test, eine Sitzung, einen Export oder eine Itembank eines anderen Kontos über die Adresse aufruft, erhält „Kein Zugriff“.
- Alle Formulare im Adminbereich sind gegen seitenübergreifende Anfragen geschützt, Admin-Sitzungen enden nach vier Stunden ohne Aktivität.
- Setzen Sie bei der Installation das Häkchen bei „Immer HTTPS verwenden“ (`force_https` in `lib/config.php`).

## 11. Fehlerbehebung

| Problem | Lösung |
|---|---|
| `/t/mein-test` liefert 404 | `mod_rewrite` ist nicht verfügbar: `'pretty_urls' => false` in `lib/config.php` setzen |
| „Invalid form token“ | Cookies für den Adminbereich sind blockiert oder die Seite war zu lange geöffnet: neu laden |
| Upload scheitert ohne Meldung | Die Datei überschreitet `upload_max_filesize` des Hosters |
| Umlaute erscheinen falsch | CSV in Excel als „CSV UTF-8“ speichern |
| PHP-Fehler anzeigen | Vorübergehend `'debug' => true` in `lib/config.php` setzen |

## 12. Technische Hinweise

- Die CAT-Berechnung (`lib/cat.php`) wurde mit einer unabhängigen Implementierung abgeglichen: Bei 200 zufälligen Antwortmustern über alle vier Modelle stimmten θ, SEM, Iteminformation und die drei Auswahlkriterien auf etwa 1e-14 überein.
- Jede Antwort wird in einer Datenbanktransaktion verarbeitet. Doppelklicks oder ein zweiter Tab können dieselbe Frage daher nicht zweimal speichern.
- Schriften: Instrument Sans und IBM Plex Serif, beide unter der SIL Open Font License (`assets/fonts`, Lizenztexte liegen bei). IBM Plex ist unverändert enthalten.

## Dateien

```
install.php          Installationsprogramm (nach Gebrauch löschen)
t.php                Seiten für Teilnehmende
admin/               Adminbereich (Übersicht, Tests, Itembanken, Sitzungen, Export, Neuberechnung, Konten, Hilfe)
lib/cat.php          IRT-Modelle, EAP, Itemauswahl, Abbruchregeln
lib/csv_import.php   Import von Itembanken
lib/schema.php       Datenbanktabellen und automatische Aktualisierung
lib/texts.php        Texte für Teilnehmende in sechs Sprachen
lib/i18n.php         Übersetzungsfunktionen
lib/lang/            Übersetzungen des Adminbereichs (de, fr, es, ja, zh)
lib/bootstrap.php    Konfiguration, Datenbank, Sicherheit, Hilfsfunktionen
assets/              Stylesheet, Schriften, Admin-Skript, Icon
examples/            Beispiel-Itembanken, score_with_mirt.R
README.md            englische Fassung; README.de.md diese Fassung
```

## Lizenz

MIT, siehe `LICENSE`. Die mitgelieferten Schriften (Instrument Sans, IBM Plex Serif) stehen unter der SIL Open Font License 1.1.
