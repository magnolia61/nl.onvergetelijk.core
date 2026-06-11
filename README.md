# nl.onvergetelijk.core

## Functionele beschrijving

De `core`-extensie is de centrale verwerkingshub van het OZK-systeem. Bij elke wijziging aan een deelnemer of begeleider — aanmaken, bewerken of aanpassen van custom fields — springt `core` in werking om alle relevante modules in de juiste volgorde aan te sturen.

In de praktijk doet `core` het volgende: zodra een deelnemerrecord verandert, verzamelt het de actuele informatie over deze persoon (contactgegevens, alle inschrijvingen dit jaar, evenementdetails) en roept vervolgens de configuratiefuncties aan van alle andere modules: CV-samenvatting, emailadressen, Drupal-account, ACL-groepen, wervingsinformatie en deelnamestatus. `core` is daarmee de orkestrator die losse modules samenwerkt tot een geïntegreerd systeem.

Daarnaast houdt `core` bij of een deelnemer voor het eerst wordt aangemaakt (Status 0-detectie) en past de declaratieverwerking (`pecunia`) bij.

## Afhankelijkheden

- `nl.onvergetelijk.base`
- `nl.onvergetelijk.cv`
- `nl.onvergetelijk.email`
- `nl.onvergetelijk.drupal`
- `nl.onvergetelijk.acl`
- `nl.onvergetelijk.mee`
- `nl.onvergetelijk.werving`
- `nl.onvergetelijk.partstatus` (indirect, via custom hook)

---

## Technische documentatie

### Kernfuncties

- `core_civicrm_postCommit($op, $objectName, $objectId, &$objectRef)` — bij aanmaken van een nieuw Participant triggert dit een eerste verwerking door `core_civicrm_custom` aan te roepen met een dummy groupID, zodat alle velden direct worden ingesteld.
- `core_civicrm_post($op, $objectName, $objectId, &$objectRef)` — bij aanmaken of wijzigen van een Participant: detecteert Status 0 (onvolledig) en triggert `pecunia_civicrm_participant` indien beschikbaar.
- `core_civicrm_custom($op, $groupID, $entityID, &$params)` — de hoofdmotor (±3200 regels). Doet achtereenvolgens:
  1. Basisdata verzamelen (contact, participant, event, hoofdleiding)
  2. Alle inschrijvingen dit jaar ophalen via `base_find_allpart()`
  3. Aanroepen van `cv_civicrm_configure`, `email_civicrm_configure`, `drupal_civicrm_configure`, `acl_civicrm_configure`, `mee_civicrm_configure`, `werving_civicrm_configure`
  4. DITJAAR-velden samenvatten op het contactrecord

### Custom field groups verwerkt
`core` reageert op wijzigingen in de meeste OZK custom field groups (PART, PART_LEID, DITJAAR, LEID, e.v.a.) en triggert op basis van het `groupID` de relevante subprocessen.

### Hooks geïmplementeerd
- `civicrm_postCommit`
- `civicrm_post`
- `civicrm_custom`
- `civicrm_xmlMenu`
- `civicrm_managed`
- `civicrm_install`, `civicrm_uninstall`, `civicrm_enable`, `civicrm_disable`

---

*Beheerd door Stichting Onvergetelijke Zomerkampen.*
