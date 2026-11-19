<?php
declare(strict_types=1);

/*
 * Instanzart und Schalter der Nutzerverwaltung (Etappe U0).
 *
 * `instance` in config.php kennt nur 'sandbox' und 'test'. Fehlt der Wert oder ist er unbekannt,
 * gilt die Installation als Produktion; ein Tippfehler schaltet also nie Testhilfen frei.
 * Testhilfen verlangen zusätzlich den Marker, den tools/seed.php in settings setzt
 * (wb_test_helpers_enabled). Band, noindex, keine Gästebilder und nur der Fake-Anbieter folgen
 * dagegen allein aus der Instanzart, damit eine Testinstanz auch ohne Marker als solche erkennbar ist.
 */

const WB_INSTANCES = ['sandbox', 'test'];
const WB_REGISTRATION_MODES = ['off', 'invite', 'open'];

/** 'production', 'test' oder 'sandbox'. */
function wb_instance(): string {
    $i = wb_config('instance');
    return is_string($i) && in_array($i, WB_INSTANCES, true) ? $i : 'production';
}

function wb_is_production(): bool {
    return wb_instance() === 'production';
}

/** Testhilfen (Testrollen, Sammelpostfach, Zeitverschiebung; ab U1a) nur mit Instanzart und passendem Marker. */
function wb_test_helpers_enabled(): bool {
    if (wb_is_production()) return false;
    try {
        return setting_get('instance_marker') === wb_instance();
    } catch (Throwable $e) {
        return false;
    }
}

/** Stellung der Registrierung (Abschnitt 17 des Konzepts). In U0 ohne Wirkung. */
function wb_registration_mode(): string {
    $m = setting_get('registration_mode', 'off');
    return in_array($m, WB_REGISTRATION_MODES, true) ? $m : 'off';
}

/** Schulen, Bezirke und Lizenzen geöffnet. In U0 ohne Wirkung. */
function wb_orgs_enabled(): bool {
    return setting_get('orgs_enabled', '0') === '1';
}

/** Fake-Anbieter als einzige KI ausserhalb der Produktion. */
function wb_ai_fake_only(): bool {
    return !wb_is_production();
}

/** Band am unteren Rand, das jede HTML-Seite einer Test- oder Sandbox-Instanz kennzeichnet. */
function wb_instance_band(): string {
    if (wb_is_production()) return '';
    $label = wb_instance() === 'sandbox' ? 'Sandbox' : 'Testumgebung';
    // Senkrecht am linken Rand, damit es keine Bedienelemente verdeckt (unten liegen Eingabefelder, oben die Kopfzeile).
    return '<style>.wb-instance-band{position:fixed;left:0;top:50%;transform:translateY(-50%) rotate(180deg);z-index:2147483647;background:#b45309;color:#fff;'
        . 'writing-mode:vertical-rl;font:600 11px/1 -apple-system,system-ui,sans-serif;padding:8px 3px;'
        . 'border-top-left-radius:5px;border-bottom-left-radius:5px;pointer-events:none;opacity:.88}'
        . '@media print{.wb-instance-band{display:none}}</style>'
        . '<div class="wb-instance-band" data-instance="' . wb_instance() . '">' . $label . ' · nicht für den Unterricht</div>';
}

/** Setzt ausserhalb der Produktion noindex und fügt das Band in jede HTML-Antwort ein. */
function wb_instance_page_setup(): void {
    if (wb_is_production() || PHP_SAPI === 'cli') return;
    header('X-Robots-Tag: noindex, nofollow');
    ob_start(function (string $buffer): string {
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) return $buffer;
        }
        $out = preg_replace('/<body\b[^>]*>/i', '$0' . wb_instance_band(), $buffer, 1);
        return $out ?? $buffer;
    });
}
