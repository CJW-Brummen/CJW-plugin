# Oude URL’s van cjw-brummen.nl

Eenmalige inventarisatie, gemaakt op 2026-09-04 met de sitemap (`/wp-sitemap.xml`) en een
bezoek aan elke pagina van de huidige, oude website. De oude site is alleen te crawlen *tot* de
overstap; daarna is deze lijst het enige wat er nog van bestaat.

Er komt bewust géén redirectcode in het thema of de plugin: de doorverwijzingen horen bij de
hosting (`.htaccess`, of het redirectscherm van de hostingpartij). Onderaan staat een blok dat je
kunt plakken.

## Wat opviel

- **61 adressen**, allemaal bereikbaar (HTTP 200), geen bestaande redirects.
- Elke pagina heeft dezelfde `<title>` (“CJW Zomerkampen”); alleen de sectiepagina’s hebben een kop. De
  35 detailadressen (`/praktische-info/*`, `/summercamp/*`, `/rent/*`, `/sponsors/*`, `/sponsor-blok/*`,
  `/contacts/*`) zijn losse items van eigen berichttypen die op de oude site als blokken op een
  sectiepagina werden getoond; als losse pagina zijn ze vrijwel leeg (± 400 woorden aan sjabloon).
  Ze mogen allemaal naar hun sectie.
- WordPress staat op de oude site in de submap `/wordpress/` (zie `robots.txt`); uploads staan onder
  `/wp-content/uploads/`. Neem de uploads mee of laat oude afbeeldingslinks 404 geven — die staan
  niet in de sitemap en niet in deze lijst.
- Vier persoonlijke contactpagina’s (`/contacts/<naam>/`) noemen vrijwilligers bij naam. Die komen
  op de nieuwe site niet terug; de redirect naar `/#contact` is ook een opruimactie.
- Zeven adressen vragen een **beslissing** (gemarkeerd met `?`): historie/leiding, activiteiten,
  CJW goes USA, reünie en donateurs hebben op de nieuwe site geen eigen plek.

## Alle adressen

| Oud adres | Kop op de oude pagina | Nieuw adres | Toelichting |
| --- | --- | --- | --- |
| `/` | CJW GAME ON! | `/` | Zelfde adres; geen redirect nodig. |
| `/activiteiten/` | Activiteiten | `/zomerkamp/ ?` | BESLISSING: oude activiteitenpagina. Voorstel: naar de zomerkamppagina. |
| `/cjw-goes-usa/` | CJW goes USA | `/ ?` | BESLISSING: historisch project. Voorstel: homepage, of 410 als het niet terugkomt. |
| `/contact/` | Contact | `/#contact` | Contactsectie op de homepage. Persoonlijke contactpagina’s (namen van vrijwilligers) bestaan op de nieuwe site niet. |
| `/contacts/cjw-zomerkamp/` | — | `/#contact` | Contactsectie op de homepage. Persoonlijke contactpagina’s (namen van vrijwilligers) bestaan op de nieuwe site niet. |
| `/contacts/koen-spee/` | — | `/#contact` | Contactsectie op de homepage. Persoonlijke contactpagina’s (namen van vrijwilligers) bestaan op de nieuwe site niet. |
| `/contacts/peter-zijlstra/` | — | `/#contact` | Contactsectie op de homepage. Persoonlijke contactpagina’s (namen van vrijwilligers) bestaan op de nieuwe site niet. |
| `/contacts/victor-kuipers/` | — | `/#contact` | Contactsectie op de homepage. Persoonlijke contactpagina’s (namen van vrijwilligers) bestaan op de nieuwe site niet. |
| `/donateurs/` | Donateurs | `/#sponsoren ?` | BESLISSING: donateurspagina. Voorstel: sponsorsectie, tot er een eigen doneerpagina is. |
| `/donateurs/betaling/` | De donatie is mislukt | `/#sponsoren ?` | Bedankpagina van de oude donatieflow; volgt de keuze voor /donateurs/. |
| `/inschrijven/` | Inschrijvingen zijn gesloten | `/inschrijven/` | Zelfde slug als de pagina die “Website inrichten” aanmaakt; geen redirect nodig. |
| `/inschrijven/betaling/` | Bedankt voor je inschrijving! | `/inschrijven/` | Bedankpagina van de oude iDEAL-flow. Bewaar de redirect tot de nieuwe betaalflow (kaart “iDEAL in de bevestiging”) een eigen bedankpagina heeft. |
| `/lead/leiding-of-assistent-worden/` | — | `/ ?` | BESLISSING: de oude pagina heet “Historie” en bevat de oproep voor leiding. De nieuwe site heeft (nog) geen historie- of leidingpagina; het menu van het thema verwijst wel naar #historie. Kies: een pagina “Historie” aanmaken, of naar de homepage sturen. |
| `/leiding/` | Historie | `/ ?` | BESLISSING: de oude pagina heet “Historie” en bevat de oproep voor leiding. De nieuwe site heeft (nog) geen historie- of leidingpagina; het menu van het thema verwijst wel naar #historie. Kies: een pagina “Historie” aanmaken, of naar de homepage sturen. |
| `/praktische-info/` | Praktische info | `/praktische-info/` | Zelfde slug als de pagina die “Website inrichten” aanmaakt; geen redirect nodig. |
| `/praktische-info/bagage/` | — | `/praktische-info/` | Onderwerp van de oude infopagina; op de nieuwe site één pagina. Overweeg een anker (#bagage, #vervoer …) zodra de tekst er staat. |
| `/praktische-info/bezoek-op-woensdag/` | — | `/praktische-info/` | Onderwerp van de oude infopagina; op de nieuwe site één pagina. Overweeg een anker (#bagage, #vervoer …) zodra de tekst er staat. |
| `/praktische-info/contactgegevens/` | — | `/#contact` | Contactgegevens staan op de nieuwe site in de footer/contactsectie. |
| `/praktische-info/eikenprocessierups-wespen/` | — | `/praktische-info/` | Onderwerp van de oude infopagina; op de nieuwe site één pagina. Overweeg een anker (#bagage, #vervoer …) zodra de tekst er staat. |
| `/praktische-info/route/` | — | `/praktische-info/` | Onderwerp van de oude infopagina; op de nieuwe site één pagina. Overweeg een anker (#bagage, #vervoer …) zodra de tekst er staat. |
| `/praktische-info/teken-en-de-ziekte-van-lyme/` | — | `/praktische-info/` | Onderwerp van de oude infopagina; op de nieuwe site één pagina. Overweeg een anker (#bagage, #vervoer …) zodra de tekst er staat. |
| `/praktische-info/vertrek-en-terugkomst/` | — | `/praktische-info/` | Onderwerp van de oude infopagina; op de nieuwe site één pagina. Overweeg een anker (#bagage, #vervoer …) zodra de tekst er staat. |
| `/praktische-info/vervoer/` | — | `/praktische-info/` | Onderwerp van de oude infopagina; op de nieuwe site één pagina. Overweeg een anker (#bagage, #vervoer …) zodra de tekst er staat. |
| `/praktische-info/ziekte-medicatie/` | — | `/praktische-info/` | Onderwerp van de oude infopagina; op de nieuwe site één pagina. Overweeg een anker (#bagage, #vervoer …) zodra de tekst er staat. |
| `/praktische-info/zonnebrand/` | — | `/praktische-info/` | Onderwerp van de oude infopagina; op de nieuwe site één pagina. Overweeg een anker (#bagage, #vervoer …) zodra de tekst er staat. |
| `/rent/793/` | — | `/verhuur/` | De verhuurpagina (thema-patroon “Verhuurpagina”). Maak die pagina met slug verhuur aan vóór de overstap. |
| `/rent/andere-tenten/` | — | `/verhuur/` | De verhuurpagina (thema-patroon “Verhuurpagina”). Maak die pagina met slug verhuur aan vóór de overstap. |
| `/rent/nepal-super-4x/` | — | `/verhuur/` | De verhuurpagina (thema-patroon “Verhuurpagina”). Maak die pagina met slug verhuur aan vóór de overstap. |
| `/rent/overig/` | — | `/verhuur/` | De verhuurpagina (thema-patroon “Verhuurpagina”). Maak die pagina met slug verhuur aan vóór de overstap. |
| `/rent/partytent-1x/` | — | `/verhuur/` | De verhuurpagina (thema-patroon “Verhuurpagina”). Maak die pagina met slug verhuur aan vóór de overstap. |
| `/rent/partytent-4x/` | — | `/verhuur/` | De verhuurpagina (thema-patroon “Verhuurpagina”). Maak die pagina met slug verhuur aan vóór de overstap. |
| `/rent/verhuur/` | — | `/verhuur/` | De verhuurpagina (thema-patroon “Verhuurpagina”). Maak die pagina met slug verhuur aan vóór de overstap. |
| `/reunie/` | De 24 uur van Zeddam | `/ ?` | BESLISSING: reünie “De 24 uur van Zeddam”. Voorstel: homepage, of 410. |
| `/reunie/1600/` | — | `/ ?` | BESLISSING: reünie “De 24 uur van Zeddam”. Voorstel: homepage, of 410. |
| `/sponsor-blok/hoe/` | — | `/#sponsoren` | Sponsorsectie op de homepage. Afzonderlijke sponsorpagina’s bestaan op de nieuwe site niet. |
| `/sponsor-blok/test/` | — | `/#sponsoren` | Sponsorsectie op de homepage. Afzonderlijke sponsorpagina’s bestaan op de nieuwe site niet. |
| `/sponsor-blok/wat/` | — | `/#sponsoren` | Sponsorsectie op de homepage. Afzonderlijke sponsorpagina’s bestaan op de nieuwe site niet. |
| `/sponsor-blok/wie/` | — | `/#sponsoren` | Sponsorsectie op de homepage. Afzonderlijke sponsorpagina’s bestaan op de nieuwe site niet. |
| `/sponsoren/` | Sponsoren | `/#sponsoren` | Sponsorsectie op de homepage. Afzonderlijke sponsorpagina’s bestaan op de nieuwe site niet. |
| `/sponsors/__trashed/` | — | `410` | Weggegooid item dat toch in de sitemap staat. Meld als “gone”. |
| `/sponsors/drukkerij-willems/` | — | `/#sponsoren` | Sponsorsectie op de homepage. Afzonderlijke sponsorpagina’s bestaan op de nieuwe site niet. |
| `/sponsors/https-www-uw-woningstoffeerder-nl/` | — | `/#sponsoren` | Sponsorsectie op de homepage. Afzonderlijke sponsorpagina’s bestaan op de nieuwe site niet. |
| `/sponsors/kidow/` | — | `/#sponsoren` | Sponsorsectie op de homepage. Afzonderlijke sponsorpagina’s bestaan op de nieuwe site niet. |
| `/sponsors/prezero/` | — | `/#sponsoren` | Sponsorsectie op de homepage. Afzonderlijke sponsorpagina’s bestaan op de nieuwe site niet. |
| `/sponsors/saval/` | — | `/#sponsoren` | Sponsorsectie op de homepage. Afzonderlijke sponsorpagina’s bestaan op de nieuwe site niet. |
| `/sponsors/ten-broek-rijwielen/` | — | `/#sponsoren` | Sponsorsectie op de homepage. Afzonderlijke sponsorpagina’s bestaan op de nieuwe site niet. |
| `/sponsors/twe-o/` | — | `/#sponsoren` | Sponsorsectie op de homepage. Afzonderlijke sponsorpagina’s bestaan op de nieuwe site niet. |
| `/sponsors/voskamp-hall/` | — | `/#sponsoren` | Sponsorsectie op de homepage. Afzonderlijke sponsorpagina’s bestaan op de nieuwe site niet. |
| `/sponsors/witteveen/` | — | `/#sponsoren` | Sponsorsectie op de homepage. Afzonderlijke sponsorpagina’s bestaan op de nieuwe site niet. |
| `/summercamp/ehbo/` | — | `/zomerkamp/` | Onderdeel van de oude zomerkamp-sectie; op de nieuwe site één pagina. |
| `/summercamp/eten/` | — | `/zomerkamp/` | Onderdeel van de oude zomerkamp-sectie; op de nieuwe site één pagina. |
| `/summercamp/kamperen/` | — | `/zomerkamp/` | Onderdeel van de oude zomerkamp-sectie; op de nieuwe site één pagina. |
| `/summercamp/kosten/` | — | `/zomerkamp/` | Onderdeel van de oude zomerkamp-sectie; op de nieuwe site één pagina. |
| `/summercamp/minimabeleid/` | — | `/zomerkamp/` | Onderdeel van de oude zomerkamp-sectie; op de nieuwe site één pagina. |
| `/summercamp/persoonlijke-verzorging/` | — | `/zomerkamp/` | Onderdeel van de oude zomerkamp-sectie; op de nieuwe site één pagina. |
| `/summercamp/privacy-policy/` | — | `/privacyverklaring/` | De privacypagina die “Website inrichten” als concept aanmaakt. Publiceer die vóór de overstap. |
| `/summercamp/spellen-en-tochten/` | — | `/zomerkamp/` | Onderdeel van de oude zomerkamp-sectie; op de nieuwe site één pagina. |
| `/summercamp/voor-wie/` | — | `/zomerkamp/` | Onderdeel van de oude zomerkamp-sectie; op de nieuwe site één pagina. |
| `/summercamp/wat-is-cjw-zomerkamp/` | — | `/zomerkamp/` | Onderdeel van de oude zomerkamp-sectie; op de nieuwe site één pagina. |
| `/verhuur/` | Verhuur | `/verhuur/` | De verhuurpagina (thema-patroon “Verhuurpagina”). Maak die pagina met slug verhuur aan vóór de overstap. |
| `/zomerkamp/` | Zomerkamp | `/zomerkamp/` | Zelfde slug als de pagina die “Website inrichten” aanmaakt; geen redirect nodig. |

## Plakblok voor `.htaccess`

Zet dit bovenaan de `.htaccess` van de nieuwe site, vóór het WordPress-blok. Regels met een `?`
hierboven staan er als voorstel in; pas ze aan na de beslissing. Laat de redirects minstens een jaar
staan: Google, de WhatsApp-groepen en de gedrukte flyers verwijzen er nog lang naar.

```apache
# Oude cjw-brummen.nl -> nieuwe site (inventarisatie 2026-09-04)
RedirectMatch 410 ^/sponsors/__trashed/?$
Redirect 301 /inschrijven/betaling/ /inschrijven/
Redirect 301 /praktische-info/contactgegevens/ /#contact
RedirectMatch 301 ^/praktische-info/.+ /praktische-info/
Redirect 301 /summercamp/privacy-policy/ /privacyverklaring/
RedirectMatch 301 ^/summercamp/.* /zomerkamp/
RedirectMatch 301 ^/rent/.* /verhuur/
RedirectMatch 301 ^/sponsoren/?$ /#sponsoren
RedirectMatch 301 ^/sponsors/.* /#sponsoren
RedirectMatch 301 ^/sponsor-blok/.* /#sponsoren
RedirectMatch 301 ^/contact/?$ /#contact
RedirectMatch 301 ^/contacts/.* /#contact
# Voorstellen, nog te beslissen:
RedirectMatch 301 ^/leiding/?$ /
RedirectMatch 301 ^/lead/.* /
Redirect 301 /activiteiten/ /zomerkamp/
Redirect 301 /cjw-goes-usa/ /
RedirectMatch 301 ^/reunie/.* /
RedirectMatch 301 ^/donateurs/.* /#sponsoren
```

Let op bij `/#sponsoren` en `/#contact`: het anker gaat mee in de `Location`-header en browsers
volgen het, maar niet elke hostingpartij laat een `#` toe in het redirectscherm. Test één zo’n adres
na het plaatsen; werkt het anker niet, stuur dan naar `/` en accepteer dat de bezoeker even scrolt.

## Na de overstap

1. Dien de nieuwe sitemap (`/wp-sitemap.xml`) in bij Google Search Console en kijk de eerste weken
   onder “Pagina’s → Niet gevonden (404)” welke oude adressen je gemist hebt.
2. Vraag de hostingpartij om de 404-log van de eerste maand; alles wat daar met een verwijzer
   (referrer) in staat verdient een regel hierboven.
3. De drie pagina’s die de redirects veronderstellen — `/zomerkamp/`, `/praktische-info/` en
   `/inschrijven/` — maakt “Website inrichten” (tabblad Beheer) aan. `/verhuur/` en
   `/privacyverklaring/` (gepubliceerd) moeten er vóór de overstap ook zijn.
