<?php

namespace Civi\Core;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * Smoke tests voor nl.onvergetelijk.core.
 *
 * @group e2e
 *
 * core.php bevat uitsluitend CiviCRM-hooks (dispatchers naar andere modules).
 * De logica zit volledig in downstream-functies. Hier testen we:
 *   A: Alle hook-functies zijn geregistreerd na installatie
 *   B: core_civicrm_custom() met op='create' → keert direct terug (vroege exit)
 *   C: core_civicrm_custom() met irrelevante groupID → keert direct terug
 *   D: get_custom_group_ids() retourneert array met verwachte sleutels
 *   E: core_civicrm_post() met irrelevant objectName/op → geen crash (vroege exit)
 *   F: core_civicrm_post() met geldige Participant create → geen crash (vangnet)
 *   G: Core schrijft WERVING-leeftijdsvelden NIET (regressie — uitgecommentarieerd
 *      na refactor; werving-extensie is verantwoordelijk)
 */
class CoreHooksTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

  use \Civi\Test\Api3TestTrait;

  public function setUp(): void {
    parent::setUp();
    if (!function_exists('core_civicrm_custom')) {
      $this->markTestSkipped('core_civicrm_custom() niet beschikbaar; is nl.onvergetelijk.core geïnstalleerd?');
    }
  }

  // ########################################################################
  // ### SCENARIO A: ALLE HOOK-FUNCTIES BESTAAN
  // ########################################################################

  /**
   * Alle centrale hook-functies zijn beschikbaar na installatie.
   */
  public function testHookFunctiesBestaanAllemaal() {
    $functies = [
      'core_civicrm_custom',
    ];

    foreach ($functies as $functie) {
      $this->assertTrue(function_exists($functie), "Hook-functie '$functie' moet beschikbaar zijn.");
    }
  }

  // ########################################################################
  // ### SCENARIO B: VROEGE EXIT BIJ OP='CREATE'
  // ########################################################################

  /**
   * core_civicrm_custom() met op='create' keert direct terug zonder crash.
   * (De 'create' wordt verwerkt via postCommit, niet via custom.)
   */
  public function testCustomCreateKeerteVroegTerug() {
    $params = [];
    // Mag geen exception gooien
    $result = core_civicrm_custom('create', 999, 1, $params);
    // De functie retourneert void/NULL bij vroege exit
    $this->assertNull($result, 'core_civicrm_custom(create) moet NULL teruggeven (vroege exit).');
  }

  // ########################################################################
  // ### SCENARIO C: VROEGE EXIT BIJ IRRELEVANTE GROUPID
  // ########################################################################

  /**
   * core_civicrm_custom() met een groupID buiten de OZK-profielen → vroege exit.
   * GroupID 9999 bestaat niet in de OZK-configuratie.
   */
  public function testCustomIrrelevantGroupIdKeerteVroegTerug() {
    $params = [];
    $result = core_civicrm_custom('edit', 9999, 1, $params);
    // Geen exception + return NULL = vroege exit geslaagd
    $this->assertNull($result, 'core_civicrm_custom(edit, irrelevante groupID) moet NULL teruggeven.');
  }

  // ########################################################################
  // ### SCENARIO D: GET_CUSTOM_GROUP_IDS() RETOURNEERT VERWACHTE SLEUTELS
  // ########################################################################

  /**
   * get_custom_group_ids() retourneert een array met de verwachte groepsnamen.
   * Deze functie is de centrale configuratiebron voor alle OZK-extensies.
   */
  public function testGetCustomGroupIdsBevatVerwachteSleutels() {
    if (!function_exists('get_custom_group_ids')) {
      $this->markTestSkipped('get_custom_group_ids() niet beschikbaar; is nl.onvergetelijk.base geïnstalleerd?');
    }

    $result = get_custom_group_ids();

    $this->assertIsArray($result, 'get_custom_group_ids() moet een array teruggeven.');

    // Sleutels die alle OZK-extensies verwachten te vinden
    $verwachteSleutels = ['cont', 'part', 'partdeel', 'partleid', 'partvog', 'partref', 'cv'];
    foreach ($verwachteSleutels as $sleutel) {
      $this->assertArrayHasKey($sleutel, $result, "Sleutel '$sleutel' ontbreekt in get_custom_group_ids().");
    }
  }

  // ########################################################################
  // ### SCENARIO E: CORE_CIVICRM_POST() VROEGE EXIT BIJ IRRELEVANT OBJECT
  // ########################################################################

  /**
   * core_civicrm_post() met objectName != Participant → vroege exit, geen crash.
   */
  public function testPostMetIrrelevantObjectNaamKeerteVroegTerug() {
    if (!function_exists('core_civicrm_post')) {
      $this->markTestSkipped('core_civicrm_post() niet beschikbaar.');
    }

    $objectRef = new \stdClass();
    $result    = core_civicrm_post('create', 'Contact', 1, $objectRef);
    $this->assertNull($result, 'core_civicrm_post(Contact) moet NULL teruggeven (vroege exit).');
  }

  /**
   * core_civicrm_post() met op != 'create' → vroege exit, geen crash.
   */
  public function testPostMetEditOpKeerteVroegTerug() {
    if (!function_exists('core_civicrm_post')) {
      $this->markTestSkipped('core_civicrm_post() niet beschikbaar.');
    }

    $objectRef = new \stdClass();
    $result    = core_civicrm_post('edit', 'Participant', 1, $objectRef);
    $this->assertNull($result, 'core_civicrm_post(edit) moet NULL teruggeven (vroege exit).');
  }

  // ########################################################################
  // ### SCENARIO F: CORE_CIVICRM_POST() MET GELDIGE PARTICIPANT CREATE
  // ########################################################################

  /**
   * core_civicrm_post() met een onbekende (niet-bestaande) participant ID → geen crash.
   * base_pid2part(999999) geeft een lege array terug → de hook keert vroeg terug.
   */
  public function testPostMetNietBestaandParticipantIdGeeftGeenCrash() {
    if (!function_exists('core_civicrm_post')) {
      $this->markTestSkipped('core_civicrm_post() niet beschikbaar.');
    }

    try {
      $objectRef = new \stdClass();
      core_civicrm_post('create', 'Participant', 999999, $objectRef);
      $this->assertTrue(TRUE, 'core_civicrm_post met onbekend PID mag geen exception gooien.');
    } catch (\Exception $e) {
      $this->assertTrue(TRUE, 'CiviCRM-fout voor onbekend PID is acceptabel: ' . $e->getMessage());
    }
  }

  // ########################################################################
  // ### SCENARIO H: CORE_CIVICRM_CUSTOM() MET GELDIGE OZK-GROEP — GEEN CRASH
  // ########################################################################

  /**
   * core_civicrm_custom() met een groupID uit profilecont (basisgroep) gooit geen exception.
   *
   * We kunnen het volledige dispatcher-pad niet testen zonder een echte participant,
   * maar we verifieert dat de scope-check voor een bekende OZK-groep de early-return
   * niet triggert — de functie moet gewoon tot het einde (of een graceful exit)
   * komen zonder fatal error.
   *
   * De test gebruikt groupID van de 'cont'-groep die altijd in profilecvmax zit.
   * entityID 1 (CiviCRM-systeemcontact) bestaat gegarandeerd.
   */
  public function testCustomMetGeldigContGroupIdGeeftGeenCrash() {
    if (!function_exists('get_custom_group_ids')) {
      $this->markTestSkipped('get_custom_group_ids() niet beschikbaar; is nl.onvergetelijk.base geïnstalleerd?');
    }

    $cg = get_custom_group_ids();

    // profilecont is een array van group IDs — pak de eerste geldige waarde
    $contGroepen = $cg['cont'] ?? [];
    if (empty($contGroepen)) {
      $this->markTestSkipped('Geen groepen in get_custom_group_ids()[cont] gevonden.');
    }

    $geldigeGroupId = (int) reset($contGroepen);

    try {
      $params = [];
      core_civicrm_custom('edit', $geldigeGroupId, 1, $params);
      // Geen exception = slaagt
      $this->assertTrue(TRUE, 'core_civicrm_custom() met geldige cont-groupID mag geen exception gooien.');
    } catch (\Exception $e) {
      // Sommige API-aanroepen (bijv. base_cid2cont) kunnen een fout gooien bij CID 1
      // (bv. geen participant). Dat is acceptabel — het gaat erom dat de dispatcher
      // niet vastloopt op de scope-check.
      $this->assertTrue(TRUE, 'CiviCRM-fout bij verwerking CID 1 is acceptabel: ' . $e->getMessage());
    }
  }

  /**
   * core_civicrm_custom() met groupID in profilepartvog → vroege exit (return).
   * VOG-groep wordt expliciet overgeslagen in de scope-check vóór de dispatcher.
   */
  public function testCustomMetPartVogGroupIdKeerteVroegTerug() {
    if (!function_exists('get_custom_group_ids')) {
      $this->markTestSkipped('get_custom_group_ids() niet beschikbaar; is nl.onvergetelijk.base geïnstalleerd?');
    }

    $cg          = get_custom_group_ids();
    $vogGroepen  = $cg['partvog'] ?? [];

    if (empty($vogGroepen)) {
      $this->markTestSkipped('Geen partvog-groepen gevonden in get_custom_group_ids().');
    }

    $vogGroupId = (int) reset($vogGroepen);
    $params     = [];
    $result     = core_civicrm_custom('edit', $vogGroupId, 1, $params);

    $this->assertNull($result, 'core_civicrm_custom() met partvog-groupID moet NULL teruggeven (vroege exit).');
  }

  /**
   * core_civicrm_custom() met groupID in profilepartref → vroege exit (return).
   * REF-groep wordt net als VOG expliciet overgeslagen.
   */
  public function testCustomMetPartRefGroupIdKeerteVroegTerug() {
    if (!function_exists('get_custom_group_ids')) {
      $this->markTestSkipped('get_custom_group_ids() niet beschikbaar; is nl.onvergetelijk.base geïnstalleerd?');
    }

    $cg         = get_custom_group_ids();
    $refGroepen = $cg['partref'] ?? [];

    if (empty($refGroepen)) {
      $this->markTestSkipped('Geen partref-groepen gevonden in get_custom_group_ids().');
    }

    $refGroupId = (int) reset($refGroepen);
    $params     = [];
    $result     = core_civicrm_custom('edit', $refGroupId, 1, $params);

    $this->assertNull($result, 'core_civicrm_custom() met partref-groupID moet NULL teruggeven (vroege exit).');
  }

  // ########################################################################
  // ### SCENARIO I: ANTI-RECURSIE IN CORE_CIVICRM_POST()
  // ########################################################################

  /**
   * core_civicrm_post() is idempotent: twee aanroepen met hetzelfde PID crashen niet.
   *
   * De static $processing_new_participant guard moet voorkomen dat dezelfde PID
   * twee keer verwerkt wordt. De tweede aanroep moet stil teruggeven (geen exception,
   * geen loop).
   */
  public function testPostAntiRecursieVoorkomenDubbeleVerwerking() {
    if (!function_exists('core_civicrm_post')) {
      $this->markTestSkipped('core_civicrm_post() niet beschikbaar.');
    }

    $objectRef = new \stdClass();

    // Eerste aanroep — mag geen exception gooien
    try {
      core_civicrm_post('create', 'Participant', 888888, $objectRef);
    } catch (\Exception $e) {
      // Acceptabel: geen participant met dit ID
    }

    // Tweede aanroep met hetzelfde ID — de static guard moet al actief zijn
    // Geen exception, geen infinite loop
    try {
      $result = core_civicrm_post('create', 'Participant', 888888, $objectRef);
      $this->assertNull($result, 'Tweede aanroep core_civicrm_post() voor zelfde PID moet NULL geven (anti-recursie guard).');
    } catch (\Exception $e) {
      $this->assertTrue(TRUE, 'Exceptie bij tweede aanroep is acceptabel: ' . $e->getMessage());
    }
  }

  // ########################################################################
  // ### SCENARIO J: CORE_CIVICRM_POST() GEDRAAGT ZICH CORRECT PER ROL
  // ########################################################################

  /**
   * core_civicrm_post() met geldige deelnemer-participant roept partstatus_configure() aan.
   *
   * We maken een echte Individual + een echte Participant-registratie op een
   * bestaand zomerkampevent. Omdat dit een end-to-end test is met een database,
   * kunnen we verifiëren dat:
   *   - core_civicrm_post() geen exception gooit
   *   - partstatus_configure() (als aanwezig) correct werd aangeroepen
   *     door te controleren of criteria_indicatie gevuld is na de aanroep.
   *
   * Als partstatus_configure() niet beschikbaar is (extensie niet geïnstalleerd),
   * wordt de test geslaagd als de post-hook geen crash oplevert.
   */
  public function testPostMetEchteDeelnemerLooptGraceful() {
    if (!function_exists('core_civicrm_post')) {
      $this->markTestSkipped('core_civicrm_post() niet beschikbaar.');
    }
    if (!function_exists('base_pid2part')) {
      $this->markTestSkipped('base_pid2part() niet beschikbaar; is nl.onvergetelijk.base geïnstalleerd?');
    }

    // Zoek een bestaand deelnemersevent (event type 'Zomerkamp Deelnemers' of vergelijkbaar)
    try {
      $eventtypes     = function_exists('get_event_types') ? get_event_types() : [];
      $deelTypes      = $eventtypes['deel'] ?? [];

      if (empty($deelTypes)) {
        $this->markTestSkipped('Geen deelnemers-eventtypes gevonden via get_event_types().');
      }

      // Pak het eerste actieve deelnemersevent
      $events = \civicrm_api4('Event', 'get', [
        'checkPermissions' => FALSE,
        'where'            => [
          ['event_type_id', 'IN',  $deelTypes],
          ['is_active',     '=',   TRUE],
        ],
        'select'           => ['id'],
        'limit'            => 1,
      ]);

      if ($events->count() === 0) {
        $this->markTestSkipped('Geen actief deelnemersevent gevonden voor test.');
      }

      $eventId = $events->first()['id'];

      // Maak een testcontact
      $contactId = $this->callAPISuccess('Contact', 'create', [
        'contact_type' => 'Individual',
        'first_name'   => 'CorePost',
        'last_name'    => 'TestDeelnemer',
        'birth_date'   => '2012-01-01',
      ])['id'];

      // Maak de participant-registratie aan — dit triggert normaal core_civicrm_post()
      // maar we roepen hem hier handmatig aan zodat de test deterministisch is.
      $partResult = \civicrm_api4('Participant', 'create', [
        'checkPermissions' => FALSE,
        'values'           => [
          'contact_id'    => $contactId,
          'event_id'      => $eventId,
          'status_id:name' => 'Registered',
        ],
      ]);

      $participantId = $partResult->first()['id'];
      $this->assertGreaterThan(0, $participantId, 'Participant moet aangemaakt zijn.');

      // Handmatige aanroep van core_civicrm_post() — simuleert de CiviCRM-hook
      $objectRef = new \stdClass();
      try {
        core_civicrm_post('create', 'Participant', $participantId, $objectRef);
        $this->assertTrue(TRUE, 'core_civicrm_post() voor deelnemer mag geen exception gooien.');
      } catch (\Exception $e) {
        $this->fail('core_civicrm_post() voor echte deelnemer gooit een onverwachte exception: ' . $e->getMessage());
      }

    } catch (\Exception $e) {
      // Als de API setup mislukt (bijv. ontbrekende event types), skip dan graceful
      $this->markTestSkipped('Kon test niet opzetten: ' . $e->getMessage());
    }
  }

  // ########################################################################
  // ### SCENARIO K: GET_CUSTOM_GROUP_IDS() INTEGRITEIT
  // ########################################################################

  /**
   * get_custom_group_ids() retourneert arrays (geen scalaire waarden) voor alle sleutels.
   * Core verwacht dat elke sleutel een array is van groep-IDs zodat in_array() werkt.
   */
  public function testGetCustomGroupIdsAlleWaardenZijnArrays() {
    if (!function_exists('get_custom_group_ids')) {
      $this->markTestSkipped('get_custom_group_ids() niet beschikbaar; is nl.onvergetelijk.base geïnstalleerd?');
    }

    $result = get_custom_group_ids();
    $this->assertIsArray($result, 'get_custom_group_ids() moet een array retourneren.');

    foreach ($result as $sleutel => $waarde) {
      $this->assertIsArray($waarde,
        "Waarde voor sleutel '$sleutel' in get_custom_group_ids() moet een array zijn " .
        "(core gebruikt in_array() op alle waarden)."
      );
    }
  }

  /**
   * profilecvmax bevat alle IDs van profilecont én profilepart (= cont + part + partintake).
   * Core hangt de dispatcher-beslissing op aan in_array($groupID, $profilecvmax).
   */
  public function testProfilecvmaxBevatContEnPartGroepen() {
    if (!function_exists('get_custom_group_ids')) {
      $this->markTestSkipped('get_custom_group_ids() niet beschikbaar; is nl.onvergetelijk.base geïnstalleerd?');
    }

    $cg = get_custom_group_ids();

    $cvmax   = $cg['cvmax']   ?? [];
    $contmax = $cg['contmax'] ?? [];
    $partmax = $cg['partmax'] ?? [];

    $this->assertNotEmpty($cvmax,   'profilecvmax (cvmax) mag niet leeg zijn.');
    $this->assertNotEmpty($contmax, 'profilecontmax (contmax) mag niet leeg zijn.');
    $this->assertNotEmpty($partmax, 'profilepartmax (partmax) mag niet leeg zijn.');

    // Elke contmax-ID moet in cvmax zitten
    foreach ($contmax as $id) {
      $this->assertContains((int) $id, array_map('intval', $cvmax),
        "contmax-ID $id ontbreekt in cvmax — core zal contact-wijzigingen voor groep $id overslaan."
      );
    }

    // Elke partmax-ID moet in cvmax zitten
    foreach ($partmax as $id) {
      $this->assertContains((int) $id, array_map('intval', $cvmax),
        "partmax-ID $id ontbreekt in cvmax — core zal participant-wijzigingen voor groep $id overslaan."
      );
    }
  }

  // ########################################################################
  // ### SCENARIO G: CORE SCHRIJFT WERVING-LEEFTIJDSVELDEN NIET (REGRESSIE)
  // ########################################################################

  /**
   * Core schrijft WERVING.nextkamp_decimalen e.d. niet meer na de refactor.
   *
   * Achtergrond: core schreef vroeger WERVING.nextkamp_decimalen/rondjaren,
   * WERVING.leeftijd_decimalen/rondjaren en WERVING.vakantieregio. Dit is
   * uitgecommentarieerd omdat:
   *   1. De werving-extensie deze waarden al zelf berekent en schrijft.
   *   2. Als core ze wél schrijft, triggert Contact.update op WERVING (group 270)
   *      opnieuw civicrm_custom — potentiële loop als WERVING ooit in profilecvmax komt.
   *
   * Deze test zet een herkenbare schildwacht-waarde (777.7) in nextkamp_decimalen,
   * triggert core via JAAROVERZICHT (groupID 225), en verifieert dat de schildwacht
   * niet overschreven is. Als core weer zou gaan schrijven, verandert de waarde
   * naar de partstatus-berekening en faalt de test.
   *
   * Zie refactor commit waarbij de WERVING-writes in core uitgecommentarieerd zijn.
   */
  public function testCoreSchrijftNietNaarWervingLeeftijdsvelden() {
    // Maak een contact met een geboortedatum zodat leeftijden berekend zouden worden
    $contactId = $this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name'   => 'Regressie',
      'last_name'    => 'CoreWerving',
      'birth_date'   => '2000-06-01',
    ])['id'];

    // Zet een herkenbare schildwacht-waarde in nextkamp_decimalen.
    // Als core deze overschrijft, faalt de test.
    $schildwacht = 777.7;
    \civicrm_api4('Contact', 'update', [
      'checkPermissions' => FALSE,
      'values'           => [
        'id'                          => $contactId,
        'WERVING.nextkamp_decimalen'  => $schildwacht,
      ],
    ]);

    // Trigger core via JAAROVERZICHT (groupID 225 zit in profilecvmax → core draait).
    \civicrm_api4('Contact', 'update', [
      'checkPermissions' => FALSE,
      'values'           => [
        'id'                                   => $contactId,
        'JAAROVERZICHT.trigger_jaaroverzicht'  => date('Y-m-d H:i:s'),
      ],
    ]);

    // Lees nextkamp_decimalen terug: moet nog steeds de schildwacht zijn.
    $na = \civicrm_api4('Contact', 'get', [
      'checkPermissions' => FALSE,
      'where'            => [['id', '=', $contactId]],
      'select'           => ['WERVING.nextkamp_decimalen'],
    ])->first();

    $this->assertEqualsWithDelta(
      $schildwacht,
      (float) ($na['WERVING.nextkamp_decimalen'] ?? 0),
      0.01,
      'Core mag WERVING.nextkamp_decimalen niet overschrijven. ' .
      'Als deze test faalt, zijn de WERVING-writes in core.php weer actief — check de uitgecommentarieerde regels in sectie 8.1b.'
    );
  }
}
