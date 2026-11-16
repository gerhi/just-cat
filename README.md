# JUST-CAT

**J**ust **U**pload, **S**elect, **T**est: a web application for computerized adaptive tests (CAT) based on item response theory, build as a companion to limesurvey. It needs PHP and a MySQL/MariaDB database, so it runs on ordinary shared web hosting that also runs limesurvey.

Deutsche Fassung: `README.de.md`

- **Models:** 1PL (Rasch), 2PL, 3PL, Graded Response Model (GRM)
- **Answer formats:** true/false, multiple choice, Likert
- **Estimation:** EAP with an N(0, 1) prior on 901 points over θ from −4.5 to 4.5
- **Item selection:** maximum Fisher information, posterior-weighted information or minimum expected posterior variance, optionally randomesque
- **Stopping rule:** a maximum number of items, or SEM at or below a threshold, whichever comes first
- **Participants:** no login, one link per test (`/t/name`)
- **Survey link:** a pseudonymous `?ref=` token comes in and an optional return URL goes back to the survey. Scores never leave the system in URLs.
- **Accounts:** an administrator plus any number of project leaders, who each see only their own tests, item banks and results
- **Languages:** the whole site is available in German, English, French, Spanish, Japanese and Chinese
- **Tools:** rescoring of uploaded answers, and exports in long and wide format

## 1. Installation

1. Create an empty MySQL or MariaDB database in your hosting control panel and note the host, database name, user and password.
2. Download the files (on GitHub: **Code → Download ZIP**) and unpack them. Upload the whole folder by FTP, for example to `https://example.de/cat/`. Upload the `.htaccess` files too (they are hidden files).
3. Open `https://example.de/cat/install.php`.
4. Enter the database details and click **Test connection**. The page reports whether the connection works and whether the user may create tables.
5. Enter name, user name and password (at least 10 characters) for the administrator account and click **Install**.
6. Click **Delete install.php** on the success page, or delete the file by FTP.
7. Log in at `https://example.de/cat/admin/`.

If the installer cannot write `lib/config.php`, it shows you the file's content; create the file by FTP and reload the page.

**Requirements:** PHP 8.1 or newer with `pdo_mysql`, and MySQL 5.7+ or MariaDB 10.3+. HTTPS is strongly recommended. Apache with `mod_rewrite` provides the short links `/t/name`; without it, untick "Short links" during installation and links look like `t.php?slug=name`.

**Updating:** upload the new files over the old ones, but keep `lib/config.php` and do not upload `install.php` again. The database is updated automatically the next time the login page is opened.

**Updating from version 1.1 or older:** these versions had a single admin password. After the update, log in with the user name `admin` and your old password. All existing tests and item banks belong to this account, and the example item banks are marked as shared. You can change name and user name under *Accounts*.

## 2. Item banks

An item bank is uploaded as a CSV file with one row per item. In Excel, use **Save as → CSV UTF-8**. Semicolons and decimal commas from German Excel are recognized automatically, and so are files saved as plain "CSV" (Windows-1252).

| Column | Needed for | Content |
|---|---|---|
| `item_id` | all | Unique item name, e.g. `K01`. Also the column name in the wide export. |
| `question_text` | all | Item text (plain text) |
| `response_type` | all | `dichotomous`, `multiple_choice` or `likert` |
| `model` | all | `1PL`, `2PL`, `3PL` (with dichotomous or multiple choice) or `GRM` (with likert) |
| `a` | 2PL, 3PL, GRM | Discrimination, greater than 0. Optional for 1PL (default 1 = Rasch). |
| `b` | 1PL, 2PL, 3PL | Difficulty |
| `c` | 3PL | Lower asymptote (guessing), at least 0 and below 1 |
| `b1`, `b2`, … `bk` | GRM | Thresholds, one per column, strictly increasing. k thresholds give k + 1 answer categories. |
| `option_1`, `option_2`, … | multiple choice | Answer texts. Optional for dichotomous items (default yes/no) and Likert items (one label per category). |
| `correct_option` | multiple choice | Number of the option that is scored 1. For dichotomous items optional, default 1. |
| `likert_min_label`, `likert_max_label` | – | Labels for the two ends of a Likert scale |
| `reverse` | – | `1` reverse-scores a Likert item |

**Parameter metric:** the logistic metric without D = 1.7, as reported by `mirt::coef(model, IRTpars = TRUE, simplify = TRUE)`. If your `a` parameters are on the normal-ogive metric, multiply them by 1.7.

**Scoring:** true/false and multiple-choice answers are compared with `correct_option` (score 0 or 1). For Likert items, the k-th answer option is GRM category k − 1, reversed if `reverse` is 1.

A file is imported only if every row is valid; otherwise the page lists every problem with its row number. Items cannot be edited in the admin area: fix the CSV and upload it as a new item bank.

The page of an item bank shows its **test information curve**: where on the θ scale the bank measures precisely, with reference lines for the information needed to reach SEM 0.30 and 0.40.

**Examples:** under *Item banks → Example item banks* you can add three ready-made banks with one click: Taylor Swift fandom (20 multiple-choice items, 3PL, English), Denkaufgaben (20 reasoning items, German) and Selbstorganisation (15 Likert items, GRM, German). Their parameters are illustrative, not calibrated. The CSV files are in the `examples` folder.

## 3. Tests

**Tests → New test**

- **Link name** gives the address `/t/link-name`.
- **Item bank:** cannot be changed once sessions exist.
- **Language** of the participant pages: one of the six languages. The fixed texts are in `lib/texts.php`.
- **Introduction:** plain text shown before the first question.
- **Stopping rule:** a maximum number of items and/or an SEM threshold. As a rough guide, SEM 0.30 corresponds to a reliability of about .91 and SEM 0.40 to about .84. Enter 0 to switch the SEM rule off.
- **Item selection:** see section 4.
- **Return URL** and **only allow starts with a reference token:** see section 6.
- **Active:** new tests are inactive until you tick this box.

During the test, a session cookie holds a random token and all progress is stored in the database. Reloading the page shows the same question again. If a participant closes the browser, the session stays incomplete; after 120 minutes without an answer it is shown as *abandoned* (setting `abandon_after_minutes` in `lib/config.php`).

## 4. Item selection

After each answer θ is re-estimated and the next item is chosen from the items not yet given. Two settings per test control how:

**Criterion**

- **Maximum Fisher information:** the item that is most informative at the current θ estimate. This is the classic choice, but early in a test the estimate is still rough.
- **Maximum posterior-weighted information:** the item information averaged over the whole posterior distribution of θ, which accounts for the uncertainty of the estimate.
- **Minimum expected posterior variance:** the item that is expected to reduce the posterior variance, and therefore the SEM, the most. It fits the SEM stopping rule directly.

The two Bayesian criteria tend to help most in short tests. With longer tests the three usually give very similar results.

**Randomesque k:** the next item is drawn at random from the k best items according to the criterion, and this includes the first item. With k = 1, everyone who gives the same answers sees the same items in the same order, and the first item is the same for everyone. Values of 3 to 5 spread the items over the bank (less overexposure, fewer answers passed around) at a small cost in precision.

The settings are stored with each session when it starts. Changing them affects only sessions started afterwards, and the exports contain the settings used for every session.

## 5. Accounts, roles and languages

There are two roles.

| | Administrator | Project leader |
|---|---|---|
| Upload item banks, create tests | yes | yes |
| Tests, sessions and exports | all | only their own tests |
| Item banks | all | their own, plus shared ones (read only) |
| Rescore uploaded answers | with any item bank | with their own and shared item banks |
| Create and manage accounts | yes | no |

**Creating a project leader:** under *Accounts*, the administrator enters name, user name and a starting password and passes the user name and starting password on to the person. At the first login the person has to choose a password of their own. Login works with the user name or, if one was entered, the e-mail address. The application sends no e-mails; if someone forgets their password, the administrator sets a new starting password under *Accounts*.

**Shared item banks:** the administrator can tick "Shared" in the settings of an item bank. Every project leader can then view it and use it in tests, but not change or delete it. The three example item banks are shared by default.

**Leaving the project:** an account can be deactivated (the person can no longer log in, everything else stays), or deleted after its tests and item banks have been transferred to another account. Results stay with the tests.

**Overview:** clicking "CAT admin" at the top left opens an overview of tests, item banks and recent sessions; administrators see all accounts as well, project leaders see only their own material.

**Languages:** the admin area and the installer follow the browser language at first; every user can switch language in the menu at the bottom left, and the choice is stored with the account. The language of the participant pages is set per test. Available are German, English, French, Spanish, Japanese and Chinese (simplified). This help text exists in English and German only. The translations were not checked by native speakers; the texts are in `lib/lang/*.json` (admin area) and `lib/texts.php` (participants) and can be corrected there.

## 6. Linking with LimeSurvey

### From LimeSurvey to the test

Set the **end URL** of the survey (or a link in a text question) to:

```
https://example.de/cat/t/my-test?ref={TOKEN}
```

In the presentation settings, switch on "Automatically load URL when survey complete". LimeSurvey replaces `{TOKEN}` with the participant's token, and the test stores it as `reference_token`. Without a participant list you can use the response ID instead: `?ref={SAVEDID}`.

> Do not switch on "Anonymized responses" in that survey. Otherwise LimeSurvey does not store the token with the answers and the data cannot be merged.

The reference token may contain letters, digits and `_ - . ~`, up to 128 characters.

### From the test back to LimeSurvey (optional)

In the test settings, enter a **return URL**, for example a second survey:

```
https://umfrage.example.de/index.php/654321?token={ref}&lang=de
```

`{ref}` is replaced by the reference token and `cat_complete=1` is appended. **Only the token travels back, never θ or SEM.** If the second survey uses a participant list, import the same token values into it.

### Merging the data

Download the wide export (section 7) and merge it in R:

```r
cat  <- read.csv("cat_my-test_wide_2026-11-15.csv")
lime <- read.csv("limesurvey_results.csv")
dat  <- merge(lime, cat, by.x = "token", by.y = "reference_token", all.x = TRUE)
```

If someone starts the test twice (for example in a second browser), the export contains two sessions with the same `reference_token`. Check for this before merging, for example by keeping the first completed session.

## 7. Export

**Export**

| Format | Content |
|---|---|
| **Wide** | One row per session: session ID, reference token, test, status, start and end time, duration, number of items, θ, SEM, stopping reason, selection settings, then one column per item with the scored answer (empty = not given) |
| **Long** | One row per answer: position, item, `raw_response` (option number as shown), `scored_response` (value used by the model), θ and SEM after this answer, final θ and SEM, selection settings |
| **Item parameters** | The item bank of the test as a codebook |

File styles: **R/SPSS/Python** (comma, decimal point) or **German Excel** (semicolon, decimal comma, UTF-8 BOM). Times are in UTC.

## 8. Rescoring and checking against R

**Rescore** computes θ (EAP) and SE per person from answers you upload in long format, with the same engine as the live test. Nothing is stored. Results can be shown on the page or downloaded as CSV.

- **Columns:** a person column (`person_id`, `session_id`, `person`, …), `item_id` and a response column.
- **Coding:** either scored values (0/1, or GRM categories 0 to K) or option numbers as shown (1 to n), which are then scored with `correct_option` and `reverse`.
- **Missing answers:** empty cells and `NA` count as not answered.
- The long export of this application can be uploaded unchanged. If the file contains `final_theta`, the page also shows the difference between the recalculated θ and the θ in the file.

**In R:** `examples/score_with_mirt.R` reads the *Item parameters* export and a long file and computes θ and SE twice: with **mirt** (`fscores(method = "EAP", quadpts = 901, theta_lim = c(-4.5, 4.5))` on a model with fixed parameters) and with a short **base-R** EAP that does not depend on mirt. It then compares both with the PHP results. Conversion to mirt's slope-intercept form: a1 = a, d = −a·b, g = c, and for graded items d_k = −a·b_k. The script prints `coef(mod, IRTpars = TRUE)` so you can check that the parameters arrived unchanged.

## 9. Data protection

**What the application stores:** the answers, θ and SEM after each answer, timestamps, the selection settings, the optional reference token, and a SHA-256 hash of the session token. The token itself exists only in the participant's cookie.

**What it does not store:** IP addresses, browser details, names or e-mail addresses.

**Cookie:** one session cookie per test (`cat_t{id}`, HttpOnly, SameSite=Lax, deleted when the browser is closed). It is technically necessary to run the test, so no consent banner is needed (§ 25 Abs. 2 Nr. 2 TDDDG).

**No third parties:** the fonts are part of the application. Nothing is loaded from other servers (no CDN, no analytics), and `Referrer-Policy: no-referrer` stops addresses from being passed on. After the start, the `?ref=` token is removed from the address bar and the browser history.

**Scores stay inside the system:** they are only available through the password-protected admin export, never in URLs.

**Hosting:** on shared hosting you usually cannot configure the web server logs, so the provider's access logs contain the first page request including `?ref=TOKEN`. Record in your data protection documentation: the processing agreement (AV-Vertrag) with the host, how long the provider keeps logs and whether it anonymizes IP addresses. Use a host with servers in the EU.

**Deletion:** find a session by its reference token under *Sessions*, then use *Details → Delete*. All sessions of a test can be deleted in the test settings.

**Pseudonymity:** the test data are linked to the survey only through the token; the merge happens on your analysis computer.

## 10. Security

- Delete `install.php` after installation.
- `lib/` (including `config.php` with the database password) is blocked by `lib/.htaccess`. On hosts without `.htaccess` support (nginx), make sure `/lib/` is not served; `config.php` prints nothing even if requested.
- Passwords are stored as bcrypt hashes. Everyone changes their own under *Change password*. Starting passwords set by the administrator must be replaced at the first login.
- Project leaders are separated on the server: opening a test, session, export or item bank of another account by its address gives "No access".
- All admin forms are protected against cross-site requests, and admin sessions end after four hours without activity.
- Tick "Always use HTTPS" during installation (`force_https` in `lib/config.php`).

## 11. Troubleshooting

| Problem | Solution |
|---|---|
| `/t/my-test` gives 404 | `mod_rewrite` is not available: set `'pretty_urls' => false` in `lib/config.php` |
| "Invalid form token" | Admin cookies are blocked, or the page was open too long: reload it |
| Upload fails without a message | The file exceeds the host's PHP `upload_max_filesize` |
| Umlauts are garbled | Save the CSV as "CSV UTF-8" in Excel |
| Show PHP errors | Set `'debug' => true` in `lib/config.php`, temporarily |

## 12. Technical notes

- The CAT engine (`lib/cat.php`) was checked against an independent implementation: on 200 random response patterns across all four models, θ, SEM, item information and the three selection criteria agreed to about 1e-14.
- Each answer is processed in a database transaction, so double clicks or a second tab cannot record the same question twice.
- Fonts: Instrument Sans and IBM Plex Serif, both under the SIL Open Font License (`assets/fonts`, licence texts included). IBM Plex is included unmodified.

## Files

```
install.php          installer (delete after use)
t.php                participant pages
admin/               admin area (overview, tests, item banks, sessions, export, rescore, accounts, help)
lib/cat.php          IRT models, EAP, item selection, stopping rules
lib/csv_import.php   item bank import
lib/schema.php       database tables and automatic updates
lib/texts.php        participant texts in six languages
lib/i18n.php         translation functions
lib/lang/            translations of the admin area (de, fr, es, ja, zh)
lib/bootstrap.php    configuration, database, security, helpers
assets/              stylesheet, fonts, admin script, icon
examples/            example item banks, score_with_mirt.R
README.md            this text; README.de.md in German
```

## License

MIT, see `LICENSE`. The bundled fonts (Instrument Sans, IBM Plex Serif) are under the SIL Open Font License 1.1.
