# Aspera Analysis API — Openstaande bugs (v1.19.9)

## Bug 1 — `hardcoded_link` in grid/validate mist type-exclusie
**Locatie:** `/grid/validate` callback, btn checks blok
**Probleem:** Check ontbreekt `$link_data['type'] !== 'custom_field'`, terwijl `wpb/validate` dit wél heeft. Kan false positives geven.
**Fix:** Voeg type-exclusie toe, identiek aan wpb/validate.

## Bug 2 — Dubbele `$link_data` decodering in grid/validate
**Locatie:** `/grid/validate` callback, btn checks + missing_hide_with_empty_link
**Probleem:** `$link_data` wordt twee keer gedeclareerd voor btn-elementen. De tweede decodering in het `missing_hide_with_empty_link` blok overschrijft de eerste.
**Fix:** Hergebruik `$link_data` uit het btn-blok; herstructureer de flow zodat btn-checks inclusief `hide_with_empty_link` in één blok zitten.

## Bug 3 — `$opt_fields` is dode code in forms/validate
**Locatie:** `/forms/validate` callback, regel ~1922-1926
**Probleem:** `$opt_fields` wordt gedeclareerd maar nergens gebruikt. De logica zit in een inline array eronder.
**Fix:** Verwijder `$opt_fields`.

## Bug 4 — Ontbrekend `post_type` in grid/validate response
**Locatie:** `/grid/validate` callback, response array
**Probleem:** Scant zowel `us_grid_layout` als `us_header`, maar response bevat geen `post_type`. Caller kan post types niet onderscheiden.
**Fix:** Voeg `'post_type' => $post->post_type` toe aan de response per post.

## Performance — `aspera_impreza_extra_vars()` per waarde
**Locatie:** `aspera_validate_color_value()` regel ~430
**Probleem:** Wordt bij elke kleurwaarde opnieuw aangeroepen (200× op een gemiddelde site).
**Fix:** Voeg toe als parameter of cache in een `static $cache`.

## Performance — `$attr` closure per shortcode
**Locatie:** `aspera_wpb_validate_post()` regel ~82-84
**Probleem:** Nieuwe closure per shortcode-match (100+ per template).
**Fix:** Reguliere helper met `$attrs` als parameter.

## Performance — Twee regex-passes in wpb validate
**Locatie:** `aspera_wpb_validate_post()` regel ~42 + ~49
**Probleem:** Content wordt twee keer volledig gescand met overlappende regexen.
**Fix:** Eén bredere regex, resultaten filteren.

## Token-efficiëntie — Inconsistente response-formats
**Probleem:** `wpb/validate/all` en `cpt/validate` gebruiken flat violations met herhaalde metadata. `grid/validate` en `forms/validate` groeperen per post. Gegroepeerd format is efficiënter.
**Fix:** Alle endpoints naar gegroepeerd format migreren.
