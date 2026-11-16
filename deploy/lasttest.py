#!/usr/bin/env python3
"""
lasttest.py - Simuliert viele gleichzeitige Stimmabgaben gegen abstimmen.php,
um die Serverlast beim Start einer echten Abstimmung nachzubilden, und
schreibt einen eigenständigen HTML-Bericht (auch zur Prüfung durch Dritte).

Braucht NUR die Python-Standardbibliothek (kein "pip install" nötig) -
Python 3.8 oder neuer.

Vorbereitung (einmalig, auf dem Server):
  https://<server>/tools/lasttest_vorbereiten.php aufrufen, Admin-Passwort
  eingeben, Anzahl Test-Stimmkarten festlegen, anlegen. Zeigt Basis-URL,
  Runden-ID und eine Token-Liste an - die Token-Liste in eine Datei
  speichern (eine pro Zeile), z.B. token.txt.

Nutzung:
  python3 lasttest.py --url https://<server>/abstimmen.php \
                       --round-id 42 --tokens token.txt --concurrency 300 \
                       --erwartete-teilnehmer 500

Schreibt am Ende eine Datei lasttest-bericht-<Zeitstempel>.html im selben
Ordner - die lässt sich direkt per Doppelklick im Browser öffnen oder
weitergeben, ohne dass die Konsolenausgabe noch gebraucht wird.

Danach auf dem Server unter tools/lasttest_vorbereiten.php auf
"Testrunde aufräumen" klicken, bevor der echte Betrieb weitergeht.
"""

import argparse
import concurrent.futures
import html
import http.cookiejar
import random
import statistics
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime

VOTEN = ["ja", "nein", "enthaltung"]

# Dieselbe Schwelle, mit der auch die Abstimmungsmaschine selbst dem
# Operator während einer echten Runde "auffällig verzögert" anzeigt (siehe
# VOTE_LAST_SCHWELLE_MS in neu/abstimmung_functions.php) - bewusst derselbe
# Wert, damit Lasttest-Bewertung und Live-Betrieb denselben Maßstab
# anlegen, statt zwei verschiedene Schwellen zu pflegen.
SCHWELLE_MS = 800

STANDARD_HEADER = {
    # Ein realistischer User-Agent statt des Standard-"Python-urllib/x.y" -
    # manche Hosting-Umgebungen (WAF/Bot-Erkennung) blocken bekannte
    # Skript-Signaturen pauschal.
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                  "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "de-DE,de;q=0.9",
}


def lies_token(pfad):
    # "utf-8-sig" statt "utf-8": entfernt eine eventuell vorhandene
    # Byte-Reihenfolge-Markierung (BOM) am Dateianfang automatisch - manche
    # Windows-Editoren (z.B. Notepad) speichern UTF-8-Dateien standardmaessig
    # mit dieser unsichtbaren Markierung. Ohne diese Korrektur haengt sie
    # unsichtbar vor dem ERSTEN Token in der Datei, dessen Pruefsumme passt
    # dann nicht mehr zum gespeicherten Token ("Diese Stimmkarte ist nicht
    # gueltig") - obwohl eine manuelle Kopie desselben Werts (die das
    # unsichtbare Zeichen meist nicht mitnimmt) einwandfrei funktioniert. Bei
    # Dateien ohne BOM verhaelt sich "utf-8-sig" identisch zu "utf-8".
    with open(pfad, "r", encoding="utf-8-sig") as f:
        return [zeile.strip() for zeile in f if zeile.strip()]


class UmleitungErkennenHandler(urllib.request.HTTPRedirectHandler):
    """Protokolliert jede HTTP-Weiterleitung (301/302/303/307/308), statt sie
    stillschweigend zu verfolgen wie es urllib normalerweise tut - wichtig,
    weil eine serverseitige Weiterleitung die Stimmabgabe unbemerkt auf eine
    ganz andere Seite (z.B. eine Alt-System-Startseite statt abstimmen.php)
    umlenken kann, ohne dass das als HTTP-Fehler auffällt. Folgt der
    Weiterleitung trotzdem (wie ein echter Browser es auch täte), merkt sie
    sich aber in umleitungen für die Fehlerdiagnose."""
    def __init__(self):
        super().__init__()
        self.umleitungen = []

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        self.umleitungen.append((req.full_url, code, newurl))
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def anfrage(url, daten=None, timeout=15, ssl_kontext=None, cookie_jar=None):
    """Eine einzelne HTTP-Anfrage (GET ohne daten, POST mit daten). Gibt
    (dauer_sekunden, http_status, fehlertext_oder_None, antworttext, umleitungen) zurück -
    antworttext wird gebraucht, weil abstimmen.php einen Fehler (z.B.
    "Abstimmung nicht mehr aktiv") mit normalem HTTP-Status 200 anzeigt, nur
    eben ohne die Erfolgsmarkierung im Seiteninhalt - ein Blick auf den
    Statuscode allein reicht hier nicht. umleitungen ist eine Liste von
    (von_url, code, nach_url) für jede tatsächlich erfolgte Weiterleitung -
    eine unerwartete Weiterleitung kann die Stimmabgabe unbemerkt auf eine
    ganz andere Seite umlenken, ohne dass das als HTTP-Fehler auffällt.
    cookie_jar (optional): wird dasselbe http.cookiejar.CookieJar-Objekt für
    die GET- und die anschliessende POST-Anfrage EINER simulierten Person
    übergeben, verhält sich das Skript wie ein echter Browser, der das beim
    Seitenaufruf gesetzte Sitzungs-Cookie beim Absenden automatisch mitschickt -
    ohne das kam es in der Praxis vor, dass eine Stimmabgabe ohne erkennbaren
    Fehler nicht gezählt wurde, obwohl derselbe Klick im echten Browser
    funktionierte."""
    start = time.monotonic()
    redirect_handler = UmleitungErkennenHandler()
    handler_liste = [redirect_handler]
    if cookie_jar is not None:
        handler_liste.append(urllib.request.HTTPCookieProcessor(cookie_jar))
    opener = urllib.request.build_opener(*handler_liste)
    if ssl_kontext is not None:
        opener.add_handler(urllib.request.HTTPSHandler(context=ssl_kontext))
    try:
        if daten is not None:
            body = urllib.parse.urlencode(daten).encode("utf-8")
            herkunft = "{0.scheme}://{0.netloc}".format(urllib.parse.urlsplit(url))
            headers = dict(STANDARD_HEADER, **{
                # Python setzt diesen Header bei "data=" zwar ohnehin
                # automatisch, hier aber zur Klarheit explizit.
                "Content-Type": "application/x-www-form-urlencoded",
                # Ein echter Browser schickt beim Absenden eines Formulars
                # immer Referer/Origin auf genau diese Seite mit - manche
                # Sicherheitsfilter/Bot-Erkennungen werten deren Fehlen bei
                # POST-Anfragen als verdächtig (strenger als bei GET).
                "Referer": url,
                "Origin": herkunft,
                # Moderne Browser schicken bei einem normalen Formular-
                # Absenden (volle Seitennavigation, kein AJAX) diese
                # Fetch-Metadaten mit - manche Schutzmechanismen prüfen
                # inzwischen gezielt auf deren Vorhandensein.
                "Sec-Fetch-Site": "same-origin",
                "Sec-Fetch-Mode": "navigate",
                "Sec-Fetch-Dest": "document",
                "Sec-Fetch-User": "?1",
                "Upgrade-Insecure-Requests": "1",
            })
            req = urllib.request.Request(url, data=body, method="POST", headers=headers)
        else:
            req = urllib.request.Request(url, method="GET", headers=dict(STANDARD_HEADER, **{
                "Sec-Fetch-Site": "none",
                "Sec-Fetch-Mode": "navigate",
                "Sec-Fetch-Dest": "document",
                "Sec-Fetch-User": "?1",
                "Upgrade-Insecure-Requests": "1",
            }))
        with opener.open(req, timeout=timeout) as resp:
            koerper = resp.read().decode("utf-8", errors="replace")
            status = resp.getcode()
        return (time.monotonic() - start, status, None, koerper, redirect_handler.umleitungen)
    except urllib.error.HTTPError as e:
        # e selbst ist nur die kurze Statuszeile ("HTTP Error 400: Bad
        # Request") - der eigentliche Antworttext des Servers (oft mit dem
        # eigentlichen Grund, z.B. Name einer Sicherheitsregel) steht im
        # Antwortkörper, den man separat einlesen muss.
        try:
            koerper = e.read().decode("utf-8", errors="replace")
        except Exception:
            koerper = "(Antworttext konnte nicht gelesen werden)"
        return (time.monotonic() - start, e.code, f"HTTP {e.code} {e.reason} - Antwort: {koerper[:500]}", koerper, redirect_handler.umleitungen)
    except Exception as e:  # Timeout, Verbindungsfehler, etc.
        return (time.monotonic() - start, None, str(e), "", redirect_handler.umleitungen)


def post_war_erfolgreich(status, koerper):
    """abstimmen.php zeigt eine erfolgreich gezählte Stimme ausschließlich über
    die grüne Bestätigung (class="gruen") - jede andere Antwort mit Status 200
    (z.B. "Diese Abstimmung ist nicht mehr aktiv") ist dort als class="fehler"
    sichtbar, obwohl der HTTP-Status selbst unauffällig 200 ist. Nur der
    Statuscode allein wäre daher eine Scheingenauigkeit."""
    return status == 200 and 'class="gruen"' in koerper


def _div_text(koerper, klasse):
    start = koerper.find(f'class="{klasse}">')
    if start == -1:
        return None
    start += len(f'class="{klasse}">')
    ende = koerper.find('</div>', start)
    return koerper[start:ende].strip() if ende != -1 else koerper[start:start + 200].strip()


def fehlertext_aus_antwort(koerper):
    """Extrahiert den Text aus einer sichtbaren class="fehler"-Box, falls
    vorhanden. Fehlt die, aber die Seite zeigt den eigenen "Stimmkarte nicht
    gültig"-Hinweis (class="hinweis" in abstimmen.php bei ungueltigem Token),
    wird das stattdessen gemeldet - typische Ursache: das Lasttest-Paket
    verbindet sich mit einer anderen Datenbank als die echte Installation
    (z.B. falsche/veraltete config.php mitkopiert)."""
    fehler = _div_text(koerper, 'fehler')
    if fehler is not None:
        return fehler
    hinweis = _div_text(koerper, 'hinweis')
    if hinweis is not None and 'nicht gültig' in hinweis:
        return (f'{hinweis} - vermutlich verbindet sich dieses Lasttest-Paket mit einer ANDEREN '
                f'Datenbank als die echte Installation (config.php prüfen: gleicher MYSQL_HOST/'
                f'MYSQL_DATABASE/MYSQL_USER wie auf der Produktivseite?), oder Runde/Token wurden '
                f'inzwischen aufgeräumt/neu angelegt.')
    return None


def teilnehmer_simulieren(basis_url, round_id, token, ssl_kontext, votum=None):
    """Simuliert eine Person: Seite aufrufen (Stimmzettel laden), dann abstimmen -
    genau der Ablauf, den ein echtes Smartphone auch durchläuft."""
    # Eigenes Cookie-Glas PRO Person - entspricht einem eigenen Smartphone/
    # Browser; NICHT über mehrere simulierte Personen hinweg teilen.
    cookie_jar = http.cookiejar.CookieJar()

    get_url = basis_url + "?token=" + urllib.parse.quote(token)
    get_dauer, get_status, get_fehler, _, _ = anfrage(get_url, ssl_kontext=ssl_kontext, cookie_jar=cookie_jar)

    post_daten = {
        "token": token,
        "round_id": str(round_id),
        "votum": votum or random.choice(VOTEN),
    }
    post_dauer, post_status, post_fehler, post_koerper, post_umleitungen = anfrage(get_url, daten=post_daten, ssl_kontext=ssl_kontext, cookie_jar=cookie_jar)

    umleitungs_hinweis = ""
    if post_umleitungen:
        schritte = " → ".join([post_umleitungen[0][0]] + [u[2] for u in post_umleitungen])
        umleitungs_hinweis = f"⚠ Weiterleitung erkannt (landet NICHT mehr bei abstimmen.php!): {schritte}. "

    if post_fehler is None and not post_war_erfolgreich(post_status, post_koerper):
        # HTTP-Ebene war unauffällig (kein HTTPError), aber die Seite selbst
        # zeigt keine Erfolgsmarkierung - das ist ebenfalls ein Fehlschlag.
        inhaltlicher_fehler = fehlertext_aus_antwort(post_koerper)
        if inhaltlicher_fehler:
            post_fehler = f"{umleitungs_hinweis}HTTP {post_status}, aber keine Erfolgsbestätigung in der Antwort gefunden - Seite zeigt: {inhaltlicher_fehler}"
        else:
            # Auch keine sichtbare Fehlermeldung gefunden - zur Fehlersuche
            # den tatsächlichen Seitenanfang mit ausgeben, statt nur "keine
            # Erfolgsbestätigung" ohne jeden weiteren Anhaltspunkt zu melden.
            ausschnitt = " ".join(post_koerper.split())[:400]
            post_fehler = (f"{umleitungs_hinweis}HTTP {post_status}, weder Erfolgs- noch Fehlermeldung in der Antwort gefunden. "
                            f"Seiteninhalt (erste 400 Zeichen, Leerraum zusammengefasst): {ausschnitt}")
    elif post_umleitungen and post_fehler is not None:
        post_fehler = umleitungs_hinweis + post_fehler

    return {
        "get_dauer": get_dauer, "get_status": get_status, "get_fehler": get_fehler,
        "post_dauer": post_dauer, "post_status": post_status, "post_fehler": post_fehler,
    }


def perzentil(werte, p):
    if not werte:
        return 0.0
    werte = sorted(werte)
    idx = min(len(werte) - 1, int(len(werte) * p))
    return werte[idx]


def stats(werte_sekunden):
    return {
        "min": min(werte_sekunden), "mittel": statistics.mean(werte_sekunden),
        "median": statistics.median(werte_sekunden), "p95": perzentil(werte_sekunden, 0.95),
        "max": max(werte_sekunden),
    }


def verdict(erfolgsquote_pct, post_p95_ms):
    """Bewertung nach denselben Maßstäben wie der Live-Betrieb (SCHWELLE_MS).
    Gibt (stufe, text, farbe) zurück - stufe eine von "bestanden"/"auffaellig"/"durchgefallen"."""
    if erfolgsquote_pct < 100:
        return ("durchgefallen",
                f"Durchgefallen: {100 - erfolgsquote_pct:.1f}% der simulierten Stimmabgaben sind fehlgeschlagen. "
                f"Bei einer echten Abstimmung mit ca. 500 Teilnehmenden wären das reale, verlorene Stimmen - "
                f"vor dem Termin unbedingt die Fehlerursache klären (siehe unten).",
                "#dc3545")
    if post_p95_ms >= SCHWELLE_MS:
        return ("auffaellig",
                f"Auffällig: Alle Stimmen kamen an, aber die Antwortzeit für 95% der Stimmabgaben lag bei "
                f"{post_p95_ms:.0f} ms oder mehr - das ist dieselbe Schwelle ({SCHWELLE_MS} ms), ab der die "
                f"Abstimmungsmaschine dem Operator während einer echten Runde selbst einen Warnhinweis zeigt. "
                f"Die Seite funktioniert, fühlt sich für Teilnehmende aber spürbar träge an.",
                "#ffc107")
    return ("bestanden",
            f"Bestanden: Alle simulierten Stimmen wurden korrekt gezählt, 95% der Antworten kamen in unter "
            f"{post_p95_ms:.0f} ms zurück - deutlich unter der {SCHWELLE_MS}-ms-Schwelle, ab der die Abstimmungsmaschine "
            f"selbst eine Warnung anzeigen würde.",
            "#28a745")


def balken_html(werte_sekunden, einheit_ms=True):
    """Einfaches Histogramm der Antwortzeiten als reine HTML/CSS-Balken (kein
    externes Diagramm-Werkzeug nötig) - 10 gleich breite Zeitfenster von 0 bis
    zum Maximalwert, Balkenlänge = Anzahl Anfragen in diesem Fenster."""
    werte = [w * 1000 for w in werte_sekunden] if einheit_ms else list(werte_sekunden)
    maximum = max(werte) if werte else 1
    anzahl_fenster = 10
    breite = max(maximum / anzahl_fenster, 0.001)
    fenster = [0] * anzahl_fenster
    for w in werte:
        idx = min(anzahl_fenster - 1, int(w / breite))
        fenster[idx] += 1
    hoechste = max(fenster) if fenster else 1

    zeilen = []
    for i, anzahl in enumerate(fenster):
        von = i * breite
        bis = (i + 1) * breite
        breite_pct = round(100 * anzahl / hoechste) if hoechste else 0
        ueber_schwelle = von >= SCHWELLE_MS
        farbe = "#dc3545" if ueber_schwelle else "#005596"
        zeilen.append(
            f'<div class="balkenzeile">'
            f'<span class="balkenlabel">{von:.0f}–{bis:.0f} ms</span>'
            f'<div class="balkenspur"><div class="balken" style="width:{breite_pct}%; background:{farbe};"></div></div>'
            f'<span class="balkenwert">{anzahl}</span>'
            f'</div>'
        )
    return "\n".join(zeilen)


def bericht_schreiben(pfad, kontext, erg):
    stufe, verdict_text, farbe = verdict(erg["erfolgsquote_pct"], erg["post_stats"]["p95"] * 1000)

    teilnehmer_pct = round(100 * kontext["anzahl_simuliert"] / kontext["erwartete_teilnehmer"]) if kontext["erwartete_teilnehmer"] else None
    abdeckung_hinweis = ""
    if teilnehmer_pct is not None and teilnehmer_pct < 80:
        abdeckung_hinweis = (
            f'<p class="warnhinweis">⚠️ Es wurden nur {kontext["anzahl_simuliert"]} von erwarteten '
            f'{kontext["erwartete_teilnehmer"]} Teilnehmenden simuliert ({teilnehmer_pct}%) - '
            f'das Ergebnis sagt nur eingeschränkt etwas über die tatsächlich zu erwartende Last aus. '
            f'Für eine aussagekräftige Prüfung die Anzahl in lasttest_vorbereiten.php erhöhen.</p>'
        )

    fehler_html = ""
    if erg["post_fehler"]:
        beispiele = "".join(
            f'<pre class="fehlerbeispiel">{html.escape(r["post_fehler"])}</pre>'
            for r in erg["post_fehler"][:5]
        )
        fehler_html = f"""
        <h2>Fehlgeschlagene Stimmabgaben ({len(erg["post_fehler"])} von {kontext["anzahl_simuliert"]})</h2>
        <p>Beispiele (bis zu 5, identische Fehler wiederholen sich meist):</p>
        {beispiele}
        """

    def stats_zeile(label, s):
        return (f"<tr><td>{label}</td><td>{s['min']*1000:.0f} ms</td><td>{s['mittel']*1000:.0f} ms</td>"
                f"<td>{s['median']*1000:.0f} ms</td><td><strong>{s['p95']*1000:.0f} ms</strong></td>"
                f"<td>{s['max']*1000:.0f} ms</td></tr>")

    html_text = f"""<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lasttest-Bericht Abstimmungsmaschine — {html.escape(kontext["zeitstempel"])}</title>
<style>
    body {{ font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            max-width: 860px; margin: 30px auto; padding: 0 20px; color: #222; line-height: 1.55; }}
    h1 {{ font-size: 22px; }}
    h2 {{ font-size: 17px; margin-top: 32px; border-bottom: 2px solid #eee; padding-bottom: 6px; }}
    .verdict {{ padding: 18px 20px; border-radius: 10px; color: white; background: {farbe}; font-size: 16px; margin: 20px 0; }}
    .verdict .stufe {{ font-size: 20px; font-weight: bold; text-transform: uppercase; display: block; margin-bottom: 6px; }}
    table {{ border-collapse: collapse; width: 100%; margin-top: 10px; }}
    th, td {{ text-align: left; padding: 7px 10px; border-bottom: 1px solid #eee; font-size: 14px; }}
    th {{ background: #f5f5f5; }}
    .kontext-tabelle td:first-child {{ color: #555; width: 260px; }}
    .warnhinweis {{ background: #fff3cd; color: #664d03; padding: 12px 16px; border-radius: 8px; border-left: 4px solid #ffc107; }}
    .fehlerbeispiel {{ background: #fde2e2; color: #7a1414; padding: 10px 12px; border-radius: 6px; font-size: 12.5px;
                        white-space: pre-wrap; overflow-x: auto; }}
    .balkenzeile {{ display: flex; align-items: center; gap: 10px; margin: 4px 0; }}
    .balkenlabel {{ width: 110px; font-size: 12.5px; color: #555; flex-shrink: 0; }}
    .balkenspur {{ flex: 1; background: #f0f0f0; border-radius: 4px; height: 18px; overflow: hidden; }}
    .balken {{ height: 100%; border-radius: 4px; }}
    .balkenwert {{ width: 40px; text-align: right; font-size: 12.5px; color: #555; flex-shrink: 0; }}
    .fussnote {{ margin-top: 40px; font-size: 12.5px; color: #888; border-top: 1px solid #eee; padding-top: 14px; }}
</style>
</head>
<body>

<h1>Lasttest-Bericht: Abstimmungsmaschine</h1>

<table class="kontext-tabelle">
    <tr><td>Durchgeführt am</td><td>{html.escape(kontext["zeitstempel"])}</td></tr>
    <tr><td>Ziel-URL</td><td>{html.escape(kontext["url"])}</td></tr>
    <tr><td>Test-Abstimmungsrunde</td><td>{kontext["round_id"]}</td></tr>
    <tr><td>Simulierte Teilnehmende</td><td>{kontext["anzahl_simuliert"]}
        {f'von erwarteten ca. {kontext["erwartete_teilnehmer"]} ({teilnehmer_pct}%)' if teilnehmer_pct is not None else ''}</td></tr>
    <tr><td>Gleichzeitigkeit (max.)</td><td>{kontext["concurrency"]}</td></tr>
    <tr><td>Gesamtdauer des Testlaufs</td><td>{kontext["dauer_gesamt"]:.1f} Sekunden</td></tr>
</table>
{abdeckung_hinweis}

<div class="verdict">
    <span class="stufe">{stufe}</span>
    {html.escape(verdict_text)}
</div>

<h2>Erfolgsquote</h2>
<p>{erg["post_erfolge_anzahl"]} von {kontext["anzahl_simuliert"]} simulierten Stimmabgaben erfolgreich
   (<strong>{erg["erfolgsquote_pct"]:.1f}%</strong>).</p>

<h2>Antwortzeiten</h2>
<table>
    <tr><th></th><th>Minimum</th><th>Mittelwert</th><th>Median</th><th>95%-Wert</th><th>Maximum</th></tr>
    {stats_zeile("Seite aufrufen (GET)", erg["get_stats"])}
    {stats_zeile("Stimme abgeben (POST)", erg["post_stats"])}
</table>
<p style="font-size:13px; color:#555;">Der <strong>95%-Wert</strong> ist die aussagekräftigste Zahl: 95 von 100 Teilnehmenden
   hatten eine Antwortzeit <em>unter</em> diesem Wert - er zeigt die reale Erfahrung der meisten Leute besser als
   der Mittelwert, den einzelne sehr langsame Ausreißer verzerren können. Die rote Linie im Diagramm unten markiert
   die {SCHWELLE_MS}-ms-Schwelle der Abstimmungsmaschine selbst.</p>

<h2>Verteilung der Antwortzeiten beim Abstimmen (POST)</h2>
{balken_html(erg["post_dauern"])}

{fehler_html}

<div class="fussnote">
    Erzeugt mit deploy/lasttest.py. Zur Einordnung: dieser Bericht misst ausschließlich Anfragen von diesem einen
    Testrechner aus - er ersetzt keine Prüfung auf Netzwerkengpässe zwischen vielen verschiedenen Standorten/
    Mobilfunknetzen gleichzeitig, bildet aber die Serverlast (Datenbank, PHP, Webserver) realistisch nach.
</div>

</body>
</html>
"""
    with open(pfad, "w", encoding="utf-8") as f:
        f.write(html_text)


def main():
    ap = argparse.ArgumentParser(description="Lasttest für die Abstimmungsmaschine (abstimmen.php)")
    ap.add_argument("--url", required=True, help="Vollständige URL zu abstimmen.php, z.B. https://server/abstimmen.php")
    ap.add_argument("--round-id", required=True, type=int, help="Runden-ID aus lasttest_vorbereiten.php")
    ap.add_argument("--tokens", required=True, help="Textdatei mit einem Token pro Zeile")
    ap.add_argument("--concurrency", type=int, default=300, help="Wie viele Teilnehmende gleichzeitig (Standard: 300)")
    ap.add_argument("--limit", type=int, default=0, help="Höchstens so viele Token verwenden (0 = alle)")
    ap.add_argument("--erwartete-teilnehmer", type=int, default=500,
                     help="Tatsächlich erwartete Teilnehmerzahl der echten Versammlung, nur für die Einordnung im Bericht (Standard: 500)")
    ap.add_argument("--bericht", default=None, help="Pfad für den HTML-Bericht (Standard: lasttest-bericht-<Zeitstempel>.html im aktuellen Ordner)")
    ap.add_argument("--insecure", action="store_true", help="TLS-Zertifikatsprüfung überspringen (nur für selbstsignierte Testumgebungen)")
    args = ap.parse_args()

    token_liste = lies_token(args.tokens)
    if args.limit > 0:
        token_liste = token_liste[: args.limit]
    if not token_liste:
        print("Keine Token in der Datei gefunden.", file=sys.stderr)
        sys.exit(1)

    ssl_kontext = None
    if args.insecure:
        ssl_kontext = ssl.create_default_context()
        ssl_kontext.check_hostname = False
        ssl_kontext.verify_mode = ssl.CERT_NONE

    print(f"Simuliere {len(token_liste)} Teilnehmende mit bis zu {args.concurrency} gleichzeitig gegen {args.url} (Runde {args.round_id}) ...")
    start_gesamt = time.monotonic()

    ergebnisse = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.concurrency) as pool:
        futures = [
            pool.submit(teilnehmer_simulieren, args.url, args.round_id, token, ssl_kontext)
            for token in token_liste
        ]
        for i, future in enumerate(concurrent.futures.as_completed(futures), 1):
            ergebnisse.append(future.result())
            if i % 50 == 0 or i == len(futures):
                print(f"  {i}/{len(futures)} fertig ...", end="\r")

    dauer_gesamt = time.monotonic() - start_gesamt
    print()

    get_dauern = [r["get_dauer"] for r in ergebnisse]
    post_dauern = [r["post_dauer"] for r in ergebnisse]
    post_erfolge = [r for r in ergebnisse if r["post_status"] == 200 and r["post_fehler"] is None]
    post_fehler = [r for r in ergebnisse if r not in post_erfolge]
    erfolgsquote_pct = 100 * len(post_erfolge) / len(ergebnisse)

    erg = {
        "get_stats": stats(get_dauern), "post_stats": stats(post_dauern),
        "get_dauern": get_dauern, "post_dauern": post_dauern,
        "post_erfolge_anzahl": len(post_erfolge), "post_fehler": post_fehler,
        "erfolgsquote_pct": erfolgsquote_pct,
    }

    print("=" * 60)
    print(f"Gesamtdauer für alle {len(ergebnisse)} simulierten Stimmabgaben: {dauer_gesamt:.1f} s")
    print(f"Erfolgreich: {len(post_erfolge)} / {len(ergebnisse)} ({erfolgsquote_pct:.1f} %)")
    print()
    print("Seite aufrufen (GET), Antwortzeit in Sekunden:")
    gs = erg["get_stats"]
    print(f"  Minimum: {gs['min']:.2f}  Mittel: {gs['mittel']:.2f}  Median: {gs['median']:.2f}  95%: {gs['p95']:.2f}  Maximum: {gs['max']:.2f}")
    print("Stimme abgeben (POST), Antwortzeit in Sekunden:")
    ps = erg["post_stats"]
    print(f"  Minimum: {ps['min']:.2f}  Mittel: {ps['mittel']:.2f}  Median: {ps['median']:.2f}  95%: {ps['p95']:.2f}  Maximum: {ps['max']:.2f}")

    if post_fehler:
        print()
        print(f"{len(post_fehler)} fehlgeschlagene Stimmabgaben, Beispiele (ausführlich, zur Fehlersuche):")
        for r in post_fehler[:3]:
            print(f"  {r['post_fehler']}")
            print()

    zeitstempel = datetime.now()
    bericht_pfad = args.bericht or f"lasttest-bericht-{zeitstempel:%Y-%m-%d_%H%M%S}.html"
    kontext = {
        "zeitstempel": zeitstempel.strftime("%d.%m.%Y %H:%M:%S"),
        "url": args.url, "round_id": args.round_id,
        "anzahl_simuliert": len(ergebnisse), "concurrency": args.concurrency,
        "erwartete_teilnehmer": args.erwartete_teilnehmer, "dauer_gesamt": dauer_gesamt,
    }
    bericht_schreiben(bericht_pfad, kontext, erg)

    print()
    print(f"Ausführlicher HTML-Bericht gespeichert unter: {bericht_pfad}")
    print("(direkt per Doppelklick im Browser zu öffnen, auch zur Weitergabe geeignet)")
    print()
    print("Zusätzlich auf dem Server prüfen (in der Abstimmungsmaschine zeigt der Operator-Bildschirm")
    print("während/nach der Runde auch einen eigenen Hinweis auf auffällige Antwortzeiten an).")
    print("=" * 60)


if __name__ == "__main__":
    main()
