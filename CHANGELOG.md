# Changelog

Evidența schimbărilor de pe branch-ul `feature/import-first-onboarding-ui`, de la ultimul commit împins (`7e9bdb3` — "Add production website analyzer for import-first onboarding (Task 2)"). Actualizează această secțiune de fiecare dată când se adaugă lucru nou, și mută-o sub un titlu de versiune/dată când se face push.

## De la 7e9bdb3 (nepublicat încă)

### Deduplicare locații — import onboarding

Cauza inițială: adrese identice ajungeau ca locații separate în draft-ul de import, în mai multe moduri diferite. Rezolvate incremental, pe măsură ce au apărut în importuri reale:

- **Mențiuni fără nume, aceeași adresă** — se unesc acum între ele (nu doar cu o intrare "stabilă" existentă).
- **Servicii cu categorie pe o pagină, durată pe alta** — se unesc dacă numele coincide și restul câmpurilor nu se contrazic.
- **Locații cu adresă pe o pagină, telefon pe alta** — la fel, unite dacă nu există conflict real.
- **Program de funcționare generic (la nivel de business), aplicat la locație(ii)** — mutat din `OnboardingDraftConfirmationService` (unde rula silențios, o singură locație) în `OnboardingEntityDeduplicator` (rulează la normalizare, pe **toate** locațiile fără orar propriu, marcat `requires_confirmation` pentru revizuire în UI, nu scris orbește).
- **Adrese identice, nume complet diferite** (ex. numele real vs. URL-ul site-ului confundat cu nume, vs. o referință de cartier) — acum se unesc după semnătura canonică stradă+număr, indiferent de grupul de nume; numele contradictorii sunt marcate conflict pentru confirmare, nu alese silențios.
- **Format de adresă**: normalizare extinsă — numărul poate apărea înainte/după stradă, marcaj „nr" inserat oriunde, oraș/cod poștal/țară în coadă, virgulă folosită ca separator intern (`"Str. X, nr. 20, sector 1"`), abrevierea „Bulevardul"/„B-dul"/„Bd." (pe lângă „Strada"/„Str." deja existent).
- **Mențiuni fără nume și fără adresă, doar oraș** — unite când orașul și programul (inclusiv cel completat automat de la nivel de business) coincid exact.
- Fix ordine pipeline: backfill-ul de program rula **după** pasul de unire — mutat înainte, altfel intrările identice deveneau identice prea târziu ca să se mai unească.
- **Servicii/staff blocate definitiv**: cu o singură locație numită, câmpul „Locații asociate" e ascuns din UI (nimic de ales), dar rămâneau fapte `requires_confirmation=true` fără nicio modalitate de confirmare — bloca definitiv finalizarea importului. Acum se rezolvă automat la acea unică locație, fără confirmare cerută.

Fișiere: `app/DataTransferObjects/Onboarding/LocationData.php`, `app/DataTransferObjects/Onboarding/ServiceData.php`, `app/Services/Onboarding/OnboardingEntityDeduplicator.php`, `app/Services/Onboarding/OnboardingHoursValidator.php` (accept format orar fără minute, ex. `"10-21:00"`), `app/Services/Onboarding/OnboardingDraftConfirmationService.php`.

### Ecranul de review al importului (UI)

- **Rețele sociale**: input separat per link (adaugă/șterge), nu un singur câmp cu virgulă. Tip nou de câmp `social_links`.
- **Locații asociate (la Servicii)**: listă de checkbox-uri cu toate locațiile disponibile, pre-bifate pe cele care se potrivesc; checkbox „Selectează tot" când sunt >1 locații; câmpul dispare complet când există o singură locație (ca în restul aplicației). Tip nou de câmp `location_checklist`.
- **Toate secțiunile** (Identitate, Locații, Servicii, Staff, FAQ, Politici): închise implicit la deschidere; pill lângă titlu — „Necesită verificare" / „Confirmat" / fără pill dacă nu are date.
- **„Confirmă tot"**: extins la toate secțiunile (înainte exista doar la Servicii) — rezolvă blocajul unde zeci de câmpuri individuale trebuiau confirmate unul câte unul.
- Mesajul de eroare la confirmare cu decizii lipsă afișat corect în română (nu mai apărea textul brut din excepția backend, în engleză).

Fișiere: `resources/js/Pages/Onboarding/components/ImportReviewStep.tsx`, `resources/js/Pages/Onboarding/Import.tsx`, `resources/js/i18n.ts`.

### Locații din dashboard (bug afișare program)

Editarea unei locații completa silențios zilele fără orar cu un program „implicit" (09:00-18:00 etc.) indistinguibil de date reale — la Salvează, acel program fals se scria definitiv peste date. Reparat: zilele nesetate rămân goale în formular.

Fișier: `resources/js/Pages/Dashboard/Index.tsx`.

### Configurare inițială (checklist onboarding)

- **„Configurează asistentul AI"** și **„Instalează widgetul"**: completarea nu mai e dedusă automat (câmpuri AI populate la import, respectiv `widget_enabled` cu default `true`) — acum e strict manuală, prin buton „Marchează finalizat" pe fiecare pagină. Coloane noi `ai_assistant_setup_completed`, `widget_setup_completed` pe `salons` (implicit `false`).
- **„Instalează widgetul"** e acum pas **obligatoriu** (nu opțional) — blochează finalizarea configurării până e marcat.
- Bara de progres din „Configurare inițială" număra greșit pașii opționali în total, dând senzația falsă că ceva obligatoriu blochează progresul — folosește acum procentul calculat corect de backend.

Fișiere: `app/Services/Onboarding/OnboardingChecklistService.php`, `app/Models/Salon.php`, `app/Http/Controllers/AiSettingsController.php`, `app/Http/Controllers/WidgetController.php`, `database/migrations/2026_07_21_000001_add_manual_checklist_flags_to_salons_table.php`, `routes/web.php`, `resources/js/Pages/Dashboard/Index.tsx`.

### Navigare dashboard

Sidebar-ul expandează automat grupul care conține pagina curentă la fiecare navigare (înainte se întâmpla doar la prima încărcare).

Fișier: `resources/js/Pages/Dashboard/Index.tsx`.

### Infrastructură dev

`composer dev` nu mai pornește `php artisan pail` — extensia `pcntl` de care depinde nu există pe Windows, iar crash-ul lui omora tot grupul de procese (`--kill-others`), inclusiv worker-ul de coadă.

Fișier: `composer.json`.

### Teste noi/actualizate

`tests/Unit/Onboarding/OnboardingEntityDeduplicatorTest.php`, `tests/Unit/Onboarding/OnboardingHoursValidatorTest.php` (nou), `tests/Feature/Onboarding/OnboardingConfirmationMappingTest.php`, `tests/Feature/OnboardingTest.php`, `tests/Feature/AiSettingsTest.php`, `tests/Feature/WidgetEmbedTest.php`, `tests/Unit/Onboarding/OnboardingFieldSchemaTest.php`. Suita completă de onboarding: 220 teste, toate trec.

### Fundația feature-ului de import (context, nu din această sesiune)

Restul modificărilor nepublicate de pe branch (modele `Faq`/`Policy`, `OnboardingImportPageController`, `OnboardingDraftPresenter`, `OnboardingFieldSchema`, migrațiile pentru `faqs`/`policies`/`staff` fingerprint, `resources/js/Pages/Onboarding/` ca fișiere noi) fac parte din fundația feature-ului de import-first onboarding, lucrată anterior acestei sesiuni de asistență — menționate aici doar ca reper, nu detaliate.

## Cunoscute, neînchise

- Multi-locație + doar program generic la nivel de business → rămâne nefinalizat per-locație (ambiguitate reală, cere decizie explicită a userului).
- Draft-uri vechi (create înainte de aceste fix-uri) nu se corectează retroactiv — fix-urile se aplică doar la analize noi.
