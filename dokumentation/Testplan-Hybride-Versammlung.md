# Testplan: Einlasskontrolle & hybride Versammlung (Phasen 1–5)

Dieses Feature besteht aus fünf zusammenspielenden Teilen (Vorgaben, Mitgliedsausweis, Einlasskontrolle, Teilnahmeform/Anwesenheitsliste, Online-Abstimmung) und mehreren Rollen (normales Mitglied, Einlasskontrolle-Personal, Redaktion). Dieser Testplan geht systematisch alle Fälle durch – **bitte auf einer Testinstallation bzw. mit Testmitgliedern durchführen, nicht mit den echten Mitgliedsdaten kurz vor der echten Versammlung.**

Jeder Punkt hat eine Checkbox (`- [ ]`) – beim Abhaken einfach `[ ]` durch `[x]` ersetzen, oder als Liste auf Papier/am Bildschirm abarbeiten.

## 0. Testumgebung vorbereiten

- [ ] **Zwei Browser (oder ein normales Fenster + ein Inkognito-/Privat-Fenster)** bereithalten – mehrere Tests brauchen zwei gleichzeitig angemeldete Personen (z. B. ein normales Mitglied und gleichzeitig Einlasskontrolle-Personal).
- [ ] **Demo-Modus oder Login-Modul aktivieren** (Vorgaben), damit Sie ohne echtes SSO zwischen Testmitgliedern wechseln können.
- [ ] **Drei Testmitglieder anlegen** (über die eingerichtete Mitgliederquelle, z. B. die lokale Mitgliederstamm-Tabelle):
  - **Mitglied A** – ganz normales Mitglied, keine besondere Rolle.
  - **Mitglied B** – bekommt über **Admin → Rechteverwaltung** die Rolle **„Ein-/Ausgang-Team"** zugewiesen (dieselbe Rolle wie die bestehende Austritt-Funktion).
  - **Mitglied C** – zweites normales Mitglied, für den Online-Abstimmungstest.
- [ ] Unter **Admin → Vereinsspezifische Vorgaben → „Abstimmungsmaschine"** die **Basis-URL** eintragen (z. B. `http://localhost/mv` o. ä. – ohne das stimmt der Mitgliedsausweis-QR-Code nicht).
- [ ] `tools/db_schema_sync.php` einmal aufrufen, damit alle neuen Tabellen sicher angelegt sind.

## 1. Phase 1 – Grundlagen (Vorgaben)

- [ ] **1.1** Unter Admin → Vorgaben erscheint der Bereich „Einlasskontrolle & Anwesenheit" mit den drei Feldern (Mitgliedschaftsprüfung-Schalter, Versammlungsbeginn/-ende, Foto-Quelle).
- [ ] **1.2** Werte eintragen und speichern – nach dem Neuladen der Seite stehen sie unverändert wieder im Formular.
- [ ] **1.3** Bei Versammlungsbeginn/-ende absichtlich ein ungültiges Format eingeben (z. B. durch Entfernen der Uhrzeit per Entwicklertools) → Fehlermeldung „Ungültige Werte bei: …", alter Wert bleibt erhalten.

## 2. Phase 2 – Elektronischer Mitgliedsausweis

Als **Mitglied A** angemeldet:

- [ ] **2.1** Button „🪪 Mein Mitgliedsausweis" ist auf der Startseite sichtbar und führt zur Ausweis-Seite.
- [ ] **2.2** Name und Mitgliedsnummer stehen korrekt auf dem Ausweis.
- [ ] **2.3** Foto-Quelle in den Vorgaben **leer lassen** → neutraler Platzhalter (🧑-Symbol) erscheint, kein kaputtes Bild.
- [ ] **2.4** Foto-Quelle auf eine **nicht erreichbare** URL setzen (z. B. `https://beispiel-ungueltig.invalid/foto.php?mnr={mnr}`) → Seite fällt automatisch auf den Platzhalter zurück (kein kaputtes Bild-Symbol).
- [ ] **2.5** QR-Code wird angezeigt (kein Platzhaltertext/kaputtes Bild).
- [ ] **2.6** Basis-URL der Abstimmungsmaschine vorübergehend leeren → Ausweis-Seite zeigt einen Hinweistext statt des QR-Codes („QR-Code aktuell nicht verfügbar …"). Danach Basis-URL wieder eintragen.
- [ ] **2.7** Mit einer echten Handykamera den QR-Code scannen (z. B. Ausweis-Seite auf einem zweiten Gerät im selben Netz öffnen) → das Handy erkennt einen **Link**, kein „Kontakt hinzufügen"-Dialog.

## 3. Phase 3 – Einlasskontrolle

Zum Testen der Status-Fälle brauchen Sie testweise eine `status`-Spalte in `svmembers` (die echte MISS-Anbindung existiert ja noch nicht) – fragen Sie ggf. eine technisch versierte Person, folgenden SQL-Befehl einmalig über phpMyAdmin auszuführen (nur auf der Testinstallation!):

```sql
ALTER TABLE svmembers ADD COLUMN status VARCHAR(50);
UPDATE svmembers SET status = 'Stimmberechtigt' WHERE membership_number = '<MNr von Mitglied A>';
```

- [ ] **3.1** Mitglied B (Einlasskontrolle-Rolle) ist **nicht** angemeldet, ein unbeteiligter Browser scannt/öffnet den Ausweis-Link von Mitglied A → zeigt Name/Telefon/Mail als Text plus „📇 Als Kontakt speichern" – **keine** Prüfansicht.
- [ ] **3.2** Jetzt als **Mitglied B** anmelden und denselben Link öffnen → zeigt stattdessen die Einlasskontrolle-Ansicht mit Name und Mitgliedsnummer.
- [ ] **3.3** Status von Mitglied A = `Stimmberechtigt` → grüne Empfehlung „✓ Stimmberechtigt".
- [ ] **3.4** Status auf `Teilnahmeberechtigt (ohne Stimmrecht)` ändern, Link neu öffnen → blaue Empfehlung, Hinweis „keine Stimmkarte".
- [ ] **3.5** Status auf `Gesperrt` (oder `Ausgetreten`/`Gast`/…) ändern → rote Empfehlung „Nicht zugelassen" mit Begründung.
- [ ] **3.6** `status`-Spalte/-Wert entfernen (`UPDATE svmembers SET status = NULL …`) → orange „? Status unbekannt".
- [ ] **3.7** Bei Mitglied A `Teilnahmeform = 'online'` setzen (über `teilnahmeform.php`, siehe Abschnitt 4) und Status wieder auf `Stimmberechtigt` → Empfehlung ist **trotzdem rot** („Als Online-Teilnehmer:in registriert").
- [ ] **3.8** Teilnahmeform wieder auf „vor Ort" zurücksetzen. Einlasskontrolle-Ansicht öffnen, Button **„✓ Einlass + Stimmkarte"** klicken → grüne Bestätigung; in der Datenbank/Anwesenheitsliste stehen `AnwesendSeit`, `AnwesendArt = einlasskontrolle`, `StimmkarteAusgegeben = 1`.
- [ ] **3.9** Denselben QR-Link **erneut** als Mitglied B öffnen → gelber Hinweis „bereits um HH:MM eingelassen", die drei Buttons bleiben trotzdem nutzbar (für Korrekturen).
- [ ] **3.10** Mit einem **neuen** Testmitglied (ohne vorherige Anwesenheit) den Button **„✗ Abweisen"** testen → keine Anwesenheit eingetragen, aber ein Protokolleintrag vorhanden.
- [ ] **3.11** Unter **Admin → Protokoll** erscheinen die Einträge „Einlasskontrolle: Einlass bestätigt" bzw. „… verweigert" mit Mitgliedsnummer und Uhrzeit.

## 4. Phase 4 – Teilnahmeform & Anwesenheitsliste

- [ ] **4.1** Vorgabe „Teilnahmeform-Auswahl … anbieten" **ausgeschaltet** lassen → auf der Startseite erscheint kein „Teilnahmeform wählen"-Button.
- [ ] **4.2** Vorgabe einschalten → Button erscheint, führt zu `teilnahmeform.php`.
- [ ] **4.3** Als Mitglied C „Vor Ort im Saal" wählen → Bestätigung sichtbar; Seite erneut öffnen zeigt die gespeicherte Wahl (Button farblich hervorgehoben).
- [ ] **4.4** Zu „Online" wechseln → funktioniert, solange noch keine Anwesenheit erfasst ist.
- [ ] **4.5** Über die Einlasskontrolle (Abschnitt 3) die Anwesenheit von Mitglied C erfassen lassen, danach `teilnahmeform.php` erneut öffnen → Auswahl ist **gesperrt**, nur noch Lesehinweis, kein Formular mehr.
- [ ] **4.6** Versammlungsbeginn/-ende in den Vorgaben auf ein Fenster setzen, das **jetzt gerade läuft** (z. B. −1 Std. bis +1 Std.), Mitgliedschaftsprüfung-Schalter **ausschalten**. Mit einem frischen Testmitglied `index.php` aufrufen → Anwesenheit wird automatisch eingetragen (`AnwesendArt = simuliert`).
- [ ] **4.7** Zeitfenster auf die Vergangenheit setzen (z. B. gestern), neues Testmitglied aufrufen → **keine** automatische Anwesenheit.
- [ ] **4.8** Unter **Admin → Anwesenheitsliste** erscheinen alle bisher erfassten Testmitglieder mit Zeit, Teilnahmeform, Stimmkarten-Status und Erfassungsart.
- [ ] **4.9** CSV-Export herunterladen → öffnet sich korrekt in Excel/LibreOffice (Umlaute lesbar).
- [ ] **4.10** Als normales Mitglied (keine Redaktionsrolle) versuchen, `anwesenheit_csv.php` direkt aufzurufen → Zugriff verweigert (403).

## 5. Phase 5 – Online-Abstimmung

Vorbereitung: Mitglied C hat (aus 4.4) `Teilnahmeform = online` und **noch keine** erfasste Anwesenheit (falls doch, mit `?neu`-ähnlichem Reset oder einem frischen Testmitglied neu anlegen).

- [ ] **5.1** Als Mitglied A (Teilnahmeform „vor Ort" oder keine Angabe) `online_abstimmen.php` aufrufen → Hinweis „steht nur für Mitglieder … online" mit Link zur Teilnahmeform-Wahl.
- [ ] **5.2** Als Mitglied C (online) dieselbe Seite aufrufen, **keine** Abstimmungsrunde läuft → Wartebildschirm mit Drehspinner, lädt sich nach 5 Sekunden sichtbar neu.
- [ ] **5.3** Über die Beamersteuerung eine Abstimmungsrunde eröffnen. Warten, bis sich die Online-Seite automatisch neu lädt (oder manuell neu laden) → springt direkt zum Stimmzettel.
- [ ] **5.4** Abstimmen (z. B. „Ja") → grüne Bestätigung „✓ Stimme gezählt."
- [ ] **5.5** Mit einem **zweiten Browser/Gerät**, ebenfalls als Mitglied C angemeldet, `online_abstimmen.php` in derselben noch laufenden Runde aufrufen → Hinweis „bereits ein Zugang ausgegeben … wenden Sie sich an die Versammlungsleitung" (kein zweiter Stimmzettel).
- [ ] **5.6** Abstimmungsrunde beenden und auswerten, **neue** Runde eröffnen. Den alten Abstimmungs-Link von Mitglied C (aus 5.3, per Lesezeichen/Verlauf) erneut öffnen → „Diese Stimmkarte ist nicht gültig" (Rundenbindung verhindert Wiederverwendung).
- [ ] **5.7** **Regressionstest**: eine normale, gedruckte/physische Stimmkarte über mehrere aufeinanderfolgende Runden hinweg verwenden → funktioniert weiterhin in **jeder** Runde (nur Online-Token sind rundengebunden, physische Karten nicht).
- [ ] **5.8** Zusammenspiel mit Phase 3: Mitglied C (online) am echten Einlass (Einlasskontrolle-Ansicht) scannen lassen → Empfehlung ist rot/„keine Stimmkarte vor Ort", unabhängig vom Status.

## 6. Generalprobe: ein durchgehendes Szenario

Zum Abschluss einmal den kompletten Ablauf einer (verkleinerten) hybriden Versammlung mit 3–4 Testmitgliedern durchspielen, ohne zwischendurch nachzuschauen, was als Nächstes kommt:

1. Je ein Testmitglied legt sich auf „vor Ort" bzw. „online" fest.
2. Die „vor Ort"-Testmitglieder werden über die Einlasskontrolle eingelassen (inkl. eines Mitglieds mit Status „Gesperrt", das abgewiesen wird).
3. Die Versammlungsleitung eröffnet eine Abstimmung.
4. Das „vor Ort"-Mitglied stimmt über seine Stimmkarte ab, das „online"-Mitglied über `online_abstimmen.php`.
5. Die Runde wird beendet und ausgewertet – das Ergebnis zeigt die korrekte Stimmenzahl.
6. Die Anwesenheitsliste (Admin) zeigt am Ende alle Teilnehmenden mit korrekten Angaben.

## 7. Nach dem Test: Aufräumen

- [ ] Alle angelegten Testmitglieder aus der Mitgliederliste entfernen (bzw. das ganze Testjahr über „Neues Jahr anlegen" verwerfen, falls für die echte Versammlung ohnehin ein frisches Jahr genutzt wird).
- [ ] Die testweise angelegte `svmembers.status`-Spalte **nicht** einfach löschen, wenn MISS sie später ohnehin braucht – nur die Testwerte zurücksetzen.
- [ ] Vorgaben (Versammlungsbeginn/-ende, Basis-URL, Foto-Quelle usw.) auf die echten Werte für die tatsächliche Versammlung setzen.
- [ ] Einen letzten Blick ins Protokoll werfen, ob dort nur die erwarteten Test-Einträge stehen.
