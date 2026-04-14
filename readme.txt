=== Aspera Analysis API ===
Version:  1.17.2
Author:   Aspera
Requires: WordPress 5.6+, PHP 8.0+, Advanced Custom Fields (Pro), AI Engine (Pro)

== Beschrijving ==

Lichtgewicht REST API plugin voor server-side analyse van WordPress-sites die
gebruikmaken van WPBakery, ACF (Advanced Custom Fields) en het Impreza thema.
De plugin voorkomt token-overhead bij externe analyse via MCP door parsing
server-side uit te voeren en compacte, gerichte JSON terug te geven.

Vereiste plugins:
- Advanced Custom Fields (Pro) — vereist voor ACF-analyse endpoints
- AI Engine (Pro) — levert de MCP-server waarmee Claude verbinding maakt met
  de website; zonder deze plugin is externe analyse via Claude niet mogelijk

De plugin wordt uitsluitend gebruikt voor analyse- en auditdoeleinden. Ze
schrijft geen content, past geen instellingen aan en heeft geen invloed op de
frontend van de website.

== Beheer ==

De plugin wordt beheerd als AI Engine plugin, zodat bestanden rechtstreeks via
MCP kunnen worden gelezen en bijgewerkt. Het lokale bronbestand staat op:

  /Users/marcvanwageningen/Mijn Drive/07 Ai/Claude/aspera-analysis-api/aspera-analysis-api.php

Bij elke aanpassing: lokaal bijwerken, versienummer verhogen, daarna deployen
via wp_plugin_put_file of wp_plugin_alter_file.

== Authenticatie ==

Alle endpoints zijn beveiligd met een geheime sleutel. De sleutel wordt
meegegeven als query-parameter:

  ?aspera_key={sleutel}

De sleutel is opgeslagen in wp_options onder 'aspera_secret_key' (autoload: no).
Requests zonder geldige sleutel krijgen een 401 Unauthorized terug.
Gebruik altijd een cache-busting parameter (&v=N) bij herhaalde aanroepen via
WebFetch om stale responses te voorkomen.

== Endpoints ==

--- WPBakery analyse ---

GET /wp-json/aspera/v1/wpb/{id}
  Parseert WPBakery post_content van één post en geeft alleen de elementen
  terug die condities of ACF-veldverwijzingen bevatten. Structurele containers
  (vc_row, vc_column, vc_row_inner, vc_column_inner) worden altijd getoond met
  hun relevante attributen. Reduceert token-belasting met 80–99% t.o.v. directe
  post_content uitlezing. Uitbreidbaar via de filter 'aspera_field_patterns'.

GET /wp-json/aspera/v1/wpb/validate/{id}
  Valideert WPBakery shortcodes van één post op beleidsschendingen.
  Zie sectie 'Gecontroleerde regels — WPBakery'.

GET /wp-json/aspera/v1/wpb/validate/all
  Valideert us_content_template en us_page_block posts op beleidsschendingen.
  Optionele parameters:
    post_types  kommagescheiden post types
                (default: us_content_template,us_page_block)
    page        paginanummer (default: 1)
    per_page    posts per pagina, max 100 (default: 20)
  Responsvelden: page, per_page, total_posts, total_pages, posts_scanned,
                 shortcodes_scanned, posts_with_issues, violation_count, violations.

GET /wp-json/aspera/v1/wpb/similar
  Detecteert structureel vergelijkbare templates als fuseer-kandidaten.
  Vergelijking via LCS (Longest Common Subsequence) op shortcode tag-reeks.
  Attribuutwaarden, ACF-slugs en condities worden genegeerd — alleen de
  volgorde van shortcode-tags telt.
  Optionele parameters:
    threshold   minimale gelijkenis 0.0–1.0 (default: 0.80)
    post_types  kommagescheiden post types (default: us_content_template)
    max_posts   maximaal aantal posts vóór circuit breaker, max 100 (default: 50)
  Circuit breaker: als het aantal gevonden posts > max_posts, wordt de operatie
  afgebroken vóór de LCS-berekeningen starten (status: limit_exceeded, HTTP 422).
  Rekenbelasting schaalt kwadratisch: n posts = n*(n-1)/2 vergelijkingen.
  Responsvelden: posts_compared, threshold_pct, candidate_count, pairs.

--- Plugin-validatie ---

GET /wp-json/aspera/v1/plugins/validate
  Controleert aanwezigheid en activiteit van essentiële plugins.
  Rapporteert ontbrekende/inactieve essentiële plugins, extra plugins en
  WooCommerce-specifieke vereisten (Mollie, PDF Invoices).
  Burst Pro en Burst (gratis) zijn beide acceptabel.
  Responsvelden: status, missing_essential, inactive_essential,
                 essential_plugins, extra_plugins, woocommerce (indien actief).

--- Formulieren ---

GET /wp-json/aspera/v1/forms/validate
  Valideert alle us_cform shortcodes op beleidsschendingen.
  Zoekt in alle gepubliceerde post types (geen revisies).
  Decodeert server-side: items (URL-encoded JSON), success_message en
  email_message (base64 → URL-decode).
  Responsvelden per formulier: post_id, post_type, post_title, status,
                               violations, observations.
  Observations bevatten: hide_form_after_sending, fields (label/type/required),
                         missing_recommended_fields.
  Zie sectie 'Gecontroleerde regels — Formulieren'.

--- ACF analyse ---

GET /wp-json/aspera/v1/acf/group/{id}
  Geeft ACF field group terug als schone JSON: naam, key, type, choices
  en conditional_logic per veld. Compact alternatief voor directe DB-uitlezing.

GET /wp-json/aspera/v1/acf/validate/{id}
  Valideert één ACF field group op structuurfouten.
  Zie sectie 'Gecontroleerde regels — ACF structuur'.
  Opmerking: valideert alleen top-level velden; sub-fields in repeaters,
  groups en flexible content vallen buiten scope.

GET /wp-json/aspera/v1/acf/validate/slugs
  Valideert ACF veldsluggen site-wide op naamgevingsconventies.
  Context (option_page / cpt / page) wordt server-side bepaald uit de
  locatieregels van de field group.
  Zie sectie 'Gecontroleerde regels — ACF slugs'.

GET /wp-json/aspera/v1/acf/post/{id}
  Geeft alle ACF-veldwaarden van een post terug als compacte JSON.
  Compact alternatief voor wp_get_post_snapshot bij lichtere post types.

--- Impreza JSON post types ---

GET /wp-json/aspera/v1/header/{id}
  Geeft us_header JSON terug per breakpoint (default/laptops/tablets/mobiles):
  elementen, layout en options. Lege waarden worden gestript.
  us_header slaat configuratie op als native JSON, niet als WPBakery shortcodes.

GET /wp-json/aspera/v1/grid/{id}
  Geeft us_grid_layout JSON terug: elementen, layout en options.
  Lege waarden worden gestript.
  us_grid_layout slaat configuratie op als native JSON.

--- Site-paspoort ---

GET /wp-json/aspera/v1/site/passport
  Geeft een gecachede snapshot van de volledige sitestructuur:
  templates (us_content_template), page blocks (us_page_block), field groups,
  custom post types en option pages. Bevat ook site_url en table_prefix.
  Genereert automatisch opnieuw wanneer relevante posts zijn gewijzigd via
  een lazy stale-vlag mechanisme. Autosaves worden genegeerd.

GET /wp-json/aspera/v1/site/passport/refresh
  Forceert volledige regeneratie van het paspoort, ongeacht de stale-vlag.
  Gebruik dit na grote migraties of wanneer het paspoort verouderd lijkt.

== Gecontroleerde regels — Formulieren ==

Regel                        | Omschrijving
-----------------------------|---------------------------------------------------
missing_receiver_email       | receiver_email ontbreekt
hardcoded_receiver_email     | receiver_email is geen {{option/recipient_opt_*}} verwijzing
missing_button_text          | button_text ontbreekt
hardcoded_button_text        | button_text is geen {{option/bl_opt_*}} verwijzing
missing_success_message      | success_message ontbreekt
hardcoded_success_message    | success_message verwijst niet naar option page veld
missing_email_subject        | email_subject ontbreekt
missing_email_message        | email_message ontbreekt
missing_field_list           | email_message bevat geen [field_list]
missing_recaptcha            | Geen reCAPTCHA veld aanwezig
missing_email_field          | Geen veld met type "email" aanwezig
wrong_email_field_type       | E-mailveld heeft verkeerd type (niet "email")
missing_move_label           | Veld met placeholder heeft move_label niet ingeschakeld
empty_option_field           | Option page veld is leeg — formulier functioneert niet correct

Responsveld (site-wide): cform_inbound_active (bool) — us_cform_inbound post type actief

== Gecontroleerde regels — WPBakery ==

Element                 | Regel                        | Omschrijving
------------------------|------------------------------|----------------------------------
vc_row, vc_column       | css_forbidden                | css= attribuut aanwezig
vc_row                  | scroll_effect_forbidden      | scroll_effect="1" aanwezig
vc_row                  | hardcoded_bg_image           | us_bg_image= bevat numeriek ID
vc_row                  | hardcoded_bg_video           | us_bg_video= bevat directe URL
us_post_custom_field    | css_forbidden                | css= attribuut aanwezig
us_post_custom_field    | missing_hide_empty           | hide_empty="1" ontbreekt
us_post_custom_field    | missing_color_link           | color_link="0" ontbreekt
us_btn                  | missing_hide_with_empty_link | hide_with_empty_link="1" ontbreekt
us_btn                  | missing_el_class             | el_class ontbreekt
us_btn                  | empty_btn_style              | style="" — stijlobject bestaat niet meer in Impreza
us_btn                  | missing_acf_link             | bl_-label zonder ACF link
us_btn                  | hardcoded_link               | link= bevat hardcoded URL in plaats van ACF custom_field
us_btn                  | wrong_link_field_prefix      | link= verwijst naar opt_-veld zonder option/ prefix
us_page_block           | missing_remove_rows          | remove_rows ontbreekt
us_page_block           | parent_row_with_siblings     | remove_rows="parent_row" + siblings
us_image                | hardcoded_image              | image= bevat numeriek media-ID
vc_video                | vc_video_wrong_attribute     | key= gebruikt i.p.v. source=
alle (templates/blocks) | hardcoded_label              | hardcoded tekst in label=
alle (templates/blocks) | hardcoded_text               | hardcoded tekst in text=
alle post types         | wrong_option_syntax          | {{option:slug}} i.p.v. {{option/slug}} in attribuutwaarde

== Gecontroleerde regels — ACF structuur ==

Regel                        | Omschrijving
-----------------------------|---------------------------------------------------
missing_name                 | Veld zonder naam (tab-velden uitgesloten)
broken_conditional_reference | Conditional logic verwijst naar niet-bestaande key
mixed_choice_key_types       | Choices-array bevat zowel int- als string-keys

== Gecontroleerde regels — ACF slugs ==

Regel             | Context     | Omschrijving
------------------|-------------|----------------------------------------------
missing_number    | alle        | Slug eindigt niet op een volgnummer (_1, _2, …)
wrong_opt_format  | option_page | Slug begint niet met opt_
wrong_cpt_format  | cpt         | Slug bevat geen _cpt_ infix
wrong_page_format | page        | Slug bevat geen _p_ infix

Context-detectie op basis van locatieregels:
  options_page regel aanwezig       → option_page → opt_{naam}_{n}
  post_type (niet ingebouwd/Impreza) → cpt         → {naam}_cpt_{cpt}_{n}
  overig                             → page         → {naam}_p_{fieldgroup}_{n}

Tab-velden worden altijd overgeslagen.

== Bekende beperkingen ==

- /acf/validate/{id} controleert alleen top-level velden; sub-fields in
  repeaters, groups of flexible content vallen buiten scope.
- Hardcoded tekst detectie slaat enkelvoudige woorden over (bijv. label="Ja")
  om false positives op CSS-klassen en vergelijkbare waarden te voorkomen.
- /wpb/{id} en /acf/post/{id} kunnen geen onderscheid maken tussen
  "post niet gevonden" en "post heeft geen content/velden" — beide geven 404.

== Changelog ==

= 1.17.2 — 2026-04-06 =
* Nieuwe validatieregel: empty_btn_style — detecteert us_btn met style=""
  (stijl was ingesteld maar het button-stijlobject is verwijderd uit Impreza)
  style ontbreekt volledig is geen fout en wordt niet gerapporteerd

= 1.17.1 — 2026-04-06 =
* /plugins/validate: Redirection toegevoegd aan essentiële plugins

= 1.17.0 — 2026-04-06 =
* Nieuw endpoint: GET /wp-json/aspera/v1/plugins/validate
  Controleert essentiële plugins op aanwezigheid en activiteit.
  Burst Pro en Burst (gratis) beide acceptabel. WooCommerce-detectie
  met automatische controle op Mollie en PDF Invoices. Extra plugins
  worden altijd gerapporteerd als informatief.

= 1.16.2 — 2026-04-06 =
* /forms/validate: WPForms detectie toegevoegd (wpforms_detected in response)
  Scant post_content én postmeta van alle gepubliceerde posts op [wpforms shortcodes.
  Geeft post_id, post_type, post_title en deprecation-melding terug per treffer.

= 1.16.1 — 2026-04-06 =
* /forms/validate: site-wide check op us_cform_inbound post type (cform_inbound_active in response)
* /forms/validate: option page veldwaarden ophalen en controleren op leeg (empty_option_field)
  Waarden worden teruggegeven als observatie (option_values) zodat de inhoud altijd zichtbaar is
* Regelset readme bijgewerkt met empty_option_field en cform_inbound

= 1.16.0 — 2026-04-06 =
* Nieuw endpoint: GET /wp-json/aspera/v1/forms/validate
  Valideert alle us_cform shortcodes site-wide op beleidsschendingen.
  Server-side decodering van items (URL-encoded JSON), success_message en
  email_message (base64). Controleert: receiver_email, button_text en
  success_message via option page, email_subject, [field_list] in email_message,
  reCAPTCHA aanwezig, e-mailveld type, move_label bij placeholder-velden.
  Rapporteert als observatie: hide_form_after_sending, veldoverzicht met
  type/label/required, en ontbrekende aanbevolen velden.

= 1.15.4 — 2026-04-06 =
* Nieuwe validatieregel: wrong_link_field_prefix — detecteert us_btn link= die verwijst naar een
  opt_-veld zonder option/ prefix; correct formaat is {"type":"custom_field","custom_field":"option/opt_slug"}

= 1.15.3 — 2026-04-06 =
* Nieuwe validatieregel: hardcoded_link — detecteert hardcoded URL in link= attribuut van us_btn;
  geldig alleen als type != custom_field én url niet leeg is

= 1.15.2 — 2026-04-06 =
* Nieuwe validatieregel: wrong_option_syntax — detecteert {{option:veldslug}} (colon) in shortcode-attributen
  waar {{option/veldslug}} (slash) vereist is; geldt voor alle post types, niet alleen templates

= 1.15.1 — 2026-04-06 =
* Revert: aspera_check_key() terug naar query-parameter authenticatie (?aspera_key=);
  header-authenticatie was theoretisch veiliger maar onbruikbaar via WebFetch
* Aanpassing /wpb/similar: paginering verwijderd (cosmetisch — verlichtte de
  kwadratische LCS-rekenlast niet); vervangen door circuit breaker via ?max_posts=N
  (default: 50, hard cap: 100) — breekt af vóór LCS-loops bij overschrijding
  met status limit_exceeded (HTTP 422) en posts_found/max_posts in response data

= 1.15.0 — 2026-04-06 =
* Schaalbaarheid: /wpb/validate/all ondersteunt paginering via page/per_page
  (default: 20, max: 100); response bevat page, per_page, total_posts, total_pages
* Schaalbaarheid: /wpb/similar ondersteunt paginering van paren via page/per_page
  (default: 10, max: 50) — zie 1.15.1 voor correctie
* Fix: WPBakery regex vervangen door quoted-string-aware variant op 4 locaties
  ([^\]]* → (?:"[^"]*"|'[^']*'|[^\]])*) — voorkomt incorrecte afbreking bij
  attribuutwaarden die ] bevatten

= 1.14.2 — 2026-04-06 =
* Fix: aspera_strip_empty() verwijdert nu ook arrays die na recursie leeg worden
* Fix: save_post hook negeert autosaves via DOING_AUTOSAVE guard
* Fix: docstring wrong_opt_format gecorrigeerd naar 'opt_ prefix'
* Toevoeging: context_name toegevoegd aan issue-output van /acf/validate/slugs

= 1.14.1 — 2026-04-06 =
* Fix: opt_ format check gecorrigeerd — prefix staat vooraan (opt_{naam}_{n})
* Documentatie bijgewerkt in wordpress-slugs.md en audit-protocol.md

= 1.14.0 — 2026-04-06 =
* Nieuw endpoint: GET /wp-json/aspera/v1/acf/validate/slugs
  Site-wide validatie van ACF veldsluggen op naamgevingsconventies.
  Context-detectie server-side via locatieregels van de field group.
  Regels: missing_number, wrong_opt_format, wrong_cpt_format, wrong_page_format.

= 1.13.0 — 2026-04-05 =
* Authenticatie: alle endpoints beveiligd met aspera_check_key() via hash_equals()
* Nieuw endpoint: GET /wp-json/aspera/v1/site/passport
* Nieuw endpoint: GET /wp-json/aspera/v1/site/passport/refresh
* Stale-vlag mechanisme via save_post en before_delete_post hooks

= 1.12.0 — 2026-04-04 =
* Nieuw endpoint: GET /wp-json/aspera/v1/wpb/similar
  Structureel vergelijkbare templates detecteren via LCS-algoritme.
  Parameters: threshold, post_types.

= 1.11.1 — 2026-04-04 =
* Fix: false positive in hardcoded tekst check voor {{option/veldslug}} patterns

= 1.11.0 — 2026-04-04 =
* Nieuw endpoint: GET /wp-json/aspera/v1/wpb/validate/all
  Bulk-validatie over alle us_content_template en us_page_block posts.

= 1.10.0 — 2026-04-04 =
* Nieuw endpoint: GET /wp-json/aspera/v1/wpb/validate/{id}
  Beleidsvalidatie per post met herbruikbare aspera_wpb_validate_post() helper.

= 1.7.0 — 2026-04-04 =
* el_class nu zichtbaar op alle elementen in /wpb/{id} output
* Nieuwe validatieregel: missing_el_class op us_btn

= 1.4.0 — 2026-04-04 =
* Nieuwe validatieregels toegevoegd aan /wpb/validate/{id}

= 1.3.0 — 2026-04-04 =
* aspera_strip_noise() vervangen door aspera_strip_empty() (enkel lege waarden strippen)
* Nieuw endpoint: GET /wp-json/aspera/v1/header/{id}
* Nieuw endpoint: GET /wp-json/aspera/v1/grid/{id}

= 1.1.0 — 2026-04-04 =
* Nieuw endpoint: GET /wp-json/aspera/v1/acf/validate/{id}
  Structuurvalidatie van ACF field groups.

= 1.0.0 — 2026-04-04 =
* Eerste versie
* Endpoints: /wpb/{id}, /acf/group/{id}, /acf/post/{id}
