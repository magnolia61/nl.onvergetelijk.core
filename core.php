<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: core.php
 * =======================================================================================
 *   core_civicrm_postCommit()
 *   core_civicrm_post()        In de Core module: Trigger Pecunia en Detecteer Status 0
 *   core_civicrm_custom()
 *   core_civicrm_xmlMenu()
 *   core_civicrm_install()     Implementation of hook_civicrm_install
 *   core_civicrm_uninstall()   Implementation of hook_civicrm_uninstall
 *   core_civicrm_enable()      Implementation of hook_civicrm_enable
 *   core_civicrm_disable()     Implementation of hook_civicrm_disable
 *   core_civicrm_managed()
 * =======================================================================================
 */

#error_reporting(E_ALL);
#ini_set('display_errors',TRUE);
#ini_set('display_startup_errors',TRUE);

require_once 'core.civix.php';


/**
 * This example compares the submitted value of a field with its current value.
 *
 * @param string $op
 *   The type of operation being performed.
 * @param int $groupID
 *   The custom group ID.
 * @param int $entityID
 *   The entityID of the row in the custom table.
 * @param array $params
 *   The parameters that were sent into the calling function.
 */

/*

function core_civicrm_postCommit($op, $objectName, $objectId, &$objectRef) {

    if ($objectName == 'Participant' && $op == 'create') {
        
        $extdebug = 'core.dispatcher'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php

        wachthond($extdebug,3, "########################################################################");
        wachthond($extdebug,1, "### POSTCOMMIT: Triggering custom logic for NEW Participant: $objectId");
        wachthond($extdebug,3, "########################################################################");

        wachthond($extdebug,1, "op",            $op);
        wachthond($extdebug,1, "objectName",    $objectName);
        wachthond($extdebug,1, "objectId",      $objectId);
        wachthond($extdebug,1, "objectRef",     $objectRef);

        // Handmatige aanroep van je custom functie
        // We simuleren de parameters die core_civicrm_custom verwacht
        $groupID        = 139; // dummy part deel
        $dummy_params   = []; 
        
        core_civicrm_custom('edit', $groupID, $objectId, $dummy_params);
    }
}

*/

/**
 * =======================================================================================
 * HOOK: core_civicrm_post — Nieuwe Participant inschrijving
 * =======================================================================================
 * @description     Triggert direct na het aanmaken van een nieuwe Participant-record.
 *                  Splitst op rol:
 *                  - Deelnemer → partstatus_configure() (leeftijd + criteria + wachtlijst)
 *                  - Leiding   → intake_civicrm_configure() (intake nodig + VOG/REF status)
 *
 * @why             Bij een nieuwe inschrijving via het formulier draait core_civicrm_custom
 *                  niet (die slaat 'create' over). Hierdoor bleven de partstatus- en
 *                  intake-velden leeg totdat er handmatig een wijziging werd opgeslagen.
 *                  Deze hook vult die velden meteen bij de eerste aanmaak.
 *
 * @trigger         hook_civicrm_post (op='create', objectName='Participant')
 * @dependencies    base_pid2part(), partstatus_configure(), intake_civicrm_configure(),
 *                  base_cid2cont(), base_find_allpart()
 * =======================================================================================
 */
function core_civicrm_post($op, $objectName, $objectId, &$objectRef) {

    // Alleen nieuwe Participant-records verwerken
    if ($objectName !== 'Participant' || $op !== 'create') {
        return;
    }

    // Anti-recursie: voorkom dat een DB-write in deze functie opnieuw deze hook triggert
    static $processing_new_participant = [];
    if (isset($processing_new_participant[$objectId])) {
        return;
    }
    $processing_new_participant[$objectId] = TRUE;

    $extdebug = 'core.nieuwe_inschrijving'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CORE [POST] NIEUWE INSCHRIJVING",             "[PID: $objectId]");
    wachthond($extdebug, 2, "########################################################################");

    // ==============================================================================
    // 1.0 PARTICIPANT DATA OPHALEN
    // ==============================================================================
    if (!function_exists('base_pid2part')) {
        wachthond($extdebug, 1, "SKIP: base_pid2part() niet beschikbaar.");
        unset($processing_new_participant[$objectId]);
        return;
    }

    // force_refresh=TRUE: de static caches van base_pid2part()/base_find_allpart() kunnen
    // eerder in DIT request al gevuld zijn (bv. tijdens contact/BIO-voorverwerking) VOORDAT
    // deze participant bestond. Zonder refresh leest de hook stale data ("geen deelname")
    // en blijft de slimme kamp-bepaling (welk kamp hoort bij deze leiding) leeg.
    $array_part = base_pid2part($objectId, TRUE);
    if (empty($array_part)) {
        wachthond($extdebug, 1, "SKIP: Geen participant data gevonden voor PID $objectId.");
        unset($processing_new_participant[$objectId]);
        return;
    }

    $part_rol   = $array_part['part_rol']   ?? 'deelnemer';
    $contact_id = $array_part['contact_id'] ?? 0;

    // ==============================================================================
    // 1.5 SLIM SKIP: CONTROLEER OF CUSTOMPRE AL HEEFT GEDRAAID
    // ==============================================================================
    // Als partstatus_civicrm_customPre (groep 271) al draaide tijdens de save,
    // zijn de partstatus-velden al ingevuld. Dan hoeven we niet dubbel te draaien.
    // Sleutelnamen zijn die van base_pid2part():
    //   Deelnemer → 'criteria_indicatie' (gevuld door partstatus_configure)
    //   Leiding   → 'part_intstatus'     (gevuld door intake_civicrm_configure)
    // LET OP: GEEN vroege return meer op criteria_indicatie/part_intstatus. customPre vult
    // hooguit de partstatus-/intakevelden, maar NIET de kamp-/DITJAAR-velden (kampkort,
    // kampnaam, functie, locatie, plaats). Die moeten hieronder alsnog via core_civicrm_custom
    // berekend worden — voor zowel deelnemer (2.0) als leiding (3.0). De skip op reeds-berekende
    // partstatus/intake zit nu per rol in de betreffende tak zelf.

    wachthond($extdebug, 3, 'part_rol',   $part_rol);
    wachthond($extdebug, 3, 'contact_id', $contact_id);

    if (empty($contact_id)) {
        wachthond($extdebug, 1, "SKIP: Geen contact_id gevonden in participant data.");
        unset($processing_new_participant[$objectId]);
        return;
    }

    // ==============================================================================
    // 2.0 DEELNEMER → VOLLEDIGE HERBEREKENING (partstatus + kamp-/DITJAAR-velden)
    // ==============================================================================
    // We draaien de volledige core_civicrm_custom('edit', 139) i.p.v. alleen
    // partstatus_configure: die functie doet partstatus zélf intern (leeftijd/criteria/
    // wachtlijst, regel 1588/1606) ÉN vult de kamp-/DITJAAR-velden (blok 8.x). Zonder dit
    // bleven kampkort/kamplocatie/kampplaats/functie + DITJAAR leeg bij create, waardoor de
    // notificatie ("... voor [kamp]") leeg verstuurd werd. core_civicrm_custom slaat 'create'
    // zelf over, daarom hier expliciet als 'edit' met de PART_DEEL-groep (139).
    if ($part_rol === 'deelnemer') {

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### CORE [POST] 2.0 DEELNEMER: VOLLEDIGE HERBEREKENING",   "[DEELNEMER]");
        wachthond($extdebug, 2, "########################################################################");

        // Cache forceren zodat de zojuist aangemaakte participant zichtbaar is (zie 3.2).
        if (function_exists('base_find_allpart')) {
            base_find_allpart($contact_id, NULL, TRUE);
        }

        watchdog('civicrm_timing', base_microtimer("START nieuwe_inschrijving deelnemer-herberekening [PID: $objectId]"), NULL, WATCHDOG_DEBUG);

        $kampvelden_params = []; // by-reference param voor core_civicrm_custom; geen formulierdata
        core_civicrm_custom('edit', 139, $objectId, $kampvelden_params);

        watchdog('civicrm_timing', base_microtimer("EINDE nieuwe_inschrijving deelnemer-herberekening"), NULL, WATCHDOG_DEBUG);

        wachthond($extdebug, 1, "### CORE [POST] 2.0 DEELNEMER HERBEREKENING VOLTOOID",           "[SUCCESS]");
    }

    // ==============================================================================
    // 3.0 LEIDING → INTAKE (intake_nodig + VOG/REF status klaarzetten)
    // ==============================================================================
    if ($part_rol === 'leiding') {

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### CORE [POST] 3.0 LEIDING: INTAKE UITVOEREN",           "[LEIDING]");
        wachthond($extdebug, 2, "########################################################################");

        // --- 3.1 INTAKE (VOG/REF) ---------------------------------------------------------
        // core_civicrm_custom draait intake NIET (extint=0), dus dat doen we hier expliciet.
        // Slim skip: als customPre intake al deed (part_intstatus gezet) slaan we het over.
        $intake_al_verwerkt = !empty($array_part['part_intstatus']) && $array_part['part_intstatus'] !== 'onbekend';
        if ($intake_al_verwerkt) {
            wachthond($extdebug, 1, "SKIP intake: al verwerkt door customPre (part_intstatus aanwezig).", "[PID: $objectId]");
        } elseif (!function_exists('intake_civicrm_configure') || !function_exists('base_cid2cont') || !function_exists('base_find_allpart')) {
            wachthond($extdebug, 1, "SKIP: intake_civicrm_configure() of base-helpers niet beschikbaar.");
        } else {
            watchdog('civicrm_timing', base_microtimer("START nieuwe_inschrijving intake [PID: $objectId]"), NULL, WATCHDOG_DEBUG);

            $cont_array = base_cid2cont($contact_id) ?: [];
            $params     = []; // Lege params: geen formulierdata, pure herberekening

            $result_intake = intake_civicrm_configure($cont_array, $array_part, $params, 'nieuwe_inschrijving');
            wachthond($extdebug, 3, 'result_intake', $result_intake);

            watchdog('civicrm_timing', base_microtimer("EINDE nieuwe_inschrijving intake"), NULL, WATCHDOG_DEBUG);

            wachthond($extdebug, 1, "### CORE [POST] 3.0 INTAKE VOLTOOID",               "[SUCCESS]");
        }

        // --- 3.2 KAMP-/DITJAAR-VELDEN -----------------------------------------------------
        // Een leiding registreert op het GENERIEKE leiding-event; bij welk kamp de aanmelding
        // hoort wordt slim berekend in core_civicrm_custom (Welk_kamp → base_find_allpart →
        // primaire registratie, sectie 1.6 / 8.x). Die functie slaat 'create' over, daarom
        // roepen we 'm hier expliciet aan als 'edit' met de PART_LEID-groep (190).
        // VOORWAARDE: base_find_allpart-cache eerst forceren zodat de zojuist aangemaakte
        // participant + Welk_kamp zichtbaar zijn (anders blijft de kamp-bepaling leeg).
        if (function_exists('base_find_allpart')) {
            base_find_allpart($contact_id, NULL, TRUE);
        }

        watchdog('civicrm_timing', base_microtimer("START nieuwe_inschrijving kampvelden [PID: $objectId]"), NULL, WATCHDOG_DEBUG);

        $kampvelden_params = []; // by-reference param voor core_civicrm_custom; geen formulierdata
        core_civicrm_custom('edit', 190, $objectId, $kampvelden_params);

        watchdog('civicrm_timing', base_microtimer("EINDE nieuwe_inschrijving kampvelden"), NULL, WATCHDOG_DEBUG);

        wachthond($extdebug, 1, "### CORE [POST] 3.2 KAMPVELDEN VOLTOOID",           "[SUCCESS]");
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CORE [POST] NIEUWE INSCHRIJVING AFGEROND",       "[PID: $objectId]");
    wachthond($extdebug, 2, "########################################################################");

    unset($processing_new_participant[$objectId]);
}

function core_civicrm_custom($op, $groupID, $entityID, &$params) {

    // Static variabele om dubbele executie in dezelfde request te voorkomen
    static $processing_core_custom = [];

    // Sla de 'create' over, die doen we nu in postCommit
    if ($op == 'create') {
        return; 
    }
    
    // Alleen verwerken bij edit of create
    if ($op !== 'edit' && $op !== 'create') {
        return;
    }

    // Voorkom dubbele trigger voor dezelfde entiteit in één sessie
    if (isset($processing_core_custom[$entityID . '_' . $groupID])) {
        return;
    }

    $processing_core_custom[$entityID . '_' . $groupID] = true;

    // Start de timer
    base_microtimer("Start Custom Hook");
    $start_tijd_script = microtime(TRUE);

    // VOEG DIT TOE: Repareer inkomende datums in de params array
    if (function_exists('drupal_timestamp_sweep')) {
        drupal_timestamp_sweep($params);
    }

    $extdebug = 'core.configure'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php
    $apidebug   = FALSE;

    $extcv      = 1;  //  KAMP CV
    $extdjcont  = 1;  //  VALUES DITJAAR
    $extdjpart  = 1;  //  VALUES DITEVENT
    $extchk     = 1;  //  TBV CHECKS bij 3.X
    $exttag     = 0;  //  TAGS
    $extrel     = 1;  //  RELATED CONTACTS
    $exteval    = 1;  //  EVALUATIE 
    $extact     = 1;  //  ACTIVITIES
    $extacl     = 1;  //  ACL & PERMISSIONS (#4 / #5 / #6)
    $extdrupal  = 1;  //  DRUPAL NAME / MAIL

    $extint     = 0;  //  EXECUTE OR SKIP INTAKE PART
    $extref     = 0;
    $extvog     = 0;

    $testreg    = 1;  //  REG TESTACCOUNTS
    $regpast    = 1;  //  REG PAST EVENTS
    $extwrite   = 1;

    // ==============================================================================
    // 1. CONFIGURATIE & PROFIELEN (Via gecentraliseerde helper)
    // ==============================================================================
    $cg = get_custom_group_ids();

    // Mapping naar de vertrouwde 'legacy' namen in core.php
    $profilecont        = $cg['cont'];
    $profilepartgeld    = $cg['partgeld'];
    $profilepartdeel    = $cg['partdeel'];
    $profilepartleid    = $cg['partleid'];
    $profilepartref     = $cg['partref'];
    $profilepartvog     = $cg['partvog'];
    $profilepart        = $cg['part'];          // = partdeel + partleid
    $profileparttrain   = $cg['parttrain'];     // = part_trainingsdag
    
    $profilepartintake  = $cg['partintake'];    // = partvog (of partvog + partref)
    $profilecontmax     = $cg['contmax'];       // = cont
    $profilepartmax     = $cg['partmax'];       // = part + partintake
    $profilepartleidmax = $cg['partleidmax'];   // = partleid + partintake
    $profilecv          = $cg['cv'];            // = cont + part
    $profilecvmax       = $cg['cvmax'];         // = contmax + partmax

    // ==============================================================================
    // 2. SCOPE CHECK (Alleen Onvergetelijk profielen)
    // ==============================================================================

    if (in_array($groupID, $profilepartref))    { return;   }
    if (in_array($groupID, $profilepartvog))    { return;   }
    if (in_array($groupID, $profileparttrain))  { return;   }

    if (!in_array($groupID, $profilecvmax))     { return;   }

    // ==============================================================================
    // 3. SNELLE EXIT: WHITELIST VOOR DEELNEMER & LEIDING EVENTS
    // ==============================================================================
    // We checken alleen als de opgeslagen velden horen bij een Participant-profiel (zoals Groep 205 of 190)
    if (in_array($groupID, $profilepartmax)) {
        try {
            // Haal snel het event_type_id op van de huidige inschrijving
            $check_event = civicrm_api4('Participant', 'get', [
                'checkPermissions'  => FALSE,
                'select'            => ['event_id.event_type_id'],
                'where'             => [['id', '=', $entityID]],
            ])->first();

            $huidig_event_type = $check_event['event_id.event_type_id'] ?? 0;
            $eventtypes        = get_event_types();
            
            // Bouw de whitelist van toegestane event types (Deel All + Leid All)
            $whitelist_types   = array_merge($eventtypes['deel_all'], $eventtypes['leid_all']);

            // Stop de executie als het event type NIET in de whitelist staat
            if (!in_array($huidig_event_type, $whitelist_types)) {
//              wachthond($extdebug, 1, "CORE CUSTOM EXIT", "Event Type $huidig_event_type is geen Deel- of Leid-event. Gestopt.");
                return;
            }
        } catch (\Exception $e) { 
            // Silent failsafe: mocht de API call falen, stopt de code hier voor de zekerheid.
            // Als je liever hebt dat hij dan toch doorgaat, kun je de return weghalen.
            return; 
        }
    }

// IF CVMAX 120
//if (in_array($groupID, $profilecvmax)) { // PROFILE CONT & PART (BASIC)

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,1, "### CORE 1.X START ONVERGETELIJK CORE", "[groupID: $groupID] [op: $op] [entityID: $entityID]");
    wachthond($extdebug,3, "########################################################################");

    watchdog('civicrm_timing', base_microtimer("START 1.X get variables"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,1, "extdebug",  $extdebug);
    wachthond($extdebug,4, "entityid",  $entityID);
    wachthond($extdebug,4, "params",    $params);

    $ditkaljaar             = date("Y");
    $today_nextkamp_jaar    = NULL; 
    $today_datetime         = date("Y-m-d H:i:s");

    $diffyears              = 0;
    $eventtype              = 0;
    $tagnr_deel             = 0;
    $tagcv_deel             = NULL;
    $tagcv_deel_array       = [];
    $extcv_deel             = NULL;
    $evtcv_deel_array       = [];
    $tagcv_leid             = NULL;
    $tagcv_leid_array       = [];
    $extcv_leid             = NULL;
    $extcv_leid_array       = [];
    $tagnr_leid             = 0;
    $tagverschildeel        = 0;
    $tagverschilleid        = 0;
    $ditevent_array         = [];

    $diteventdeelyes        = 0;
    $diteventdeelmss        = 0;
    $diteventdeelnot        = 0;
    $diteventleidyes        = 0;
    $diteventleidmss        = 0;
    $diteventleidnot        = 0;

    $ditjaar_array          = [];

    $ditjaardeelyes         = 0;
    $ditjaardeelmss         = 0;
    $ditjaardeelnot         = 0;
    $ditjaarleidyes         = 0;
    $ditjaarleidmss         = 0;
    $ditjaarleidnot         = 0;

    $curcv_deel_array       = [];
    $curcv_deel_top_array   = [];
    $curcv_leid_array       = [];
    $curcv_deel_array_nr    = 0;
    $curcv_leid_array_nr    = 0;
    $evtcv_deel_array       = [];
    $evtcv_deel_top_array   = [];
    $evtcv_leid_array       = [];
    $maxcv_deel_array       = [];

    $kampgeldregeling       = NULL;

    // We halen de config op uit base.php via de nieuwe functienaam
    $eventtypes             = get_event_types();

    // 1. Map de basis types naar lokale variabelen
    $eventtypesdeel         = $eventtypes['deel'];
    $eventtypesdeeltop      = $eventtypes['deeltop'];
    $eventtypesleid         = $eventtypes['leid'];
    $eventtypesmeet         = $eventtypes['meet'];
    $eventtypestoer         = $eventtypes['toer'];   // toerusting / trainingsdag (train + workshop)
    
    // 2. Map de test types
    $eventtypesdeeltest     = $eventtypes['deeltest'];
    $eventtypesleidtest     = $eventtypes['leidtest'];
    $eventtypesdeeltoptest  = $eventtypes['toptest']; // Let op mapping: 'toptest' -> 'deeltoptest'

    // 3. Map de gecombineerde lijsten (kant-en-klaar uit de config)
    $eventtypesprod         = $eventtypes['prod'];
    $eventtypestest         = $eventtypes['test'];
    $eventtypesall          = $eventtypes['all'];

    $eventtypesdeelall      = $eventtypes['deel_all'];
    $eventtypesleidall      = $eventtypes['leid_all'];

    $new_ditjaar_intnodig   = NULL;
    $new_ditjaar_nawnodig   = 'elkjaar';  // M61 Overrule naw nodig naar jaarlijkse check voor iedereen
    $new_ditjaar_bionodig   = 'elkjaar';  // M61 Overrule bio nodig naar jaarlijkse check voor iedereen
    $new_ditjaar_refnodig   = NULL;
    $ditevent_vognodig      = NULL;

    $new_ditjaar_intstatus  = 'gedeeltelijk';   // SET DEFAULT STATUS BEFORE COMPUTING
    $new_ditjaar_nawstatus  = 'ongecheckt';     // SET DEFAULT STATUS BEFORE COMPUTING
    $new_ditjaar_biostatus  = 'ongecheckt';     // SET DEFAULT STATUS BEFORE COMPUTING
    $new_ditjaar_refstatus  = 'onbekend';       // SET DEFAULT STATUS BEFORE COMPUTING
    $new_ditjaar_vogstatus  = 'klaarzetten';    // SET DEFAULT STATUS BEFORE COMPUTING

    $new_ditpart_intstatus  = 'gedeeltelijk';   // SET DEFAULT STATUS BEFORE COMPUTING
    $new_ditpart_nawstatus  = 'ongecheckt';     // SET DEFAULT STATUS BEFORE COMPUTING
    $new_ditpart_biostatus  = 'ongecheckt';     // SET DEFAULT STATUS BEFORE COMPUTING
    $new_ditpart_refstatus  = 'onbekend';       // SET DEFAULT STATUS BEFORE COMPUTING
    $new_ditpart_vogstatus  = 'klaarzetten';    // SET DEFAULT STATUS BEFORE COMPUTING

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE 0.X START BEPAAL BASISINFO DEZE PERSOON [entityID: $entityID]","[groupID: $groupID]");
    wachthond($extdebug,3, "########################################################################");

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,4, "### CORE 0.1 GET FISCALYEAR DATA GRENZEN",            "[$today_datetime]");
    wachthond($extdebug,4, "########################################################################");

    // 2. Haal de data op via de nieuwe find_fiscalyear() functie.
    // Omdat we in find_fiscalyear() 'static' gebruiken, wordt de zware berekening OF de 
    // Civi::cache database-lookup maar ÉÉN KEER uitgevoerd per page load.
    $fiscal_data                = find_fiscalyear();

    // 3. Wijs de variabelen toe vanuit de array die find_fiscalyear() teruggeeft.
    // GEEN Civi::cache()->get() calls meer nodig hier!
    $today_fiscalyear_start     = $fiscal_data['today_start'];
    $today_fiscalyear_einde     = $fiscal_data['today_einde'];
    $today_kampjaar             = $fiscal_data['today_jaar'];

    $nexty_fiscalyear_start     = $fiscal_data['nexty_start'];
    $nexty_fiscalyear_einde     = $fiscal_data['nexty_einde'];
    $nexty_kampjaar             = $fiscal_data['nexty_jaar'];

    $grensvognoggoed1           = $fiscal_data['vognoggoed1'];
    $grensvognoggoed3           = $fiscal_data['vognoggoed3'];
    $grensrefnoggoed3           = $fiscal_data['refnoggoed3'];

    // 4. Optionele logging (alleen als severity op 4 staat)
    wachthond($extdebug, 4, 'today_kampjaar',           $today_kampjaar);
    wachthond($extdebug, 4, 'today_fiscalyear_start',   $today_fiscalyear_start);
    wachthond($extdebug, 4, 'nexty_kampjaar',           $nexty_kampjaar);
    wachthond($extdebug, 4, 'grensvognoggoed3',         $grensvognoggoed3);

    wachthond($extdebug, 4, "########################################################################");
    wachthond($extdebug, 4, "### CORE 0.2 VIND GECONFIGUREERDE DEELNAME STATUSSEN (POS/NEG)");
    wachthond($extdebug, 4, "########################################################################");

    // ################################################################################################
    // # 1. HAAL STATUSSEN OP (CACHE OF VERS)
    // ################################################################################################
    
    $status_data        = find_partstatus();

    // 2. Wijs toe aan de variabelen (Engels)
    $status_positive    = $status_data['ids']['Positive'] ?? [];
    $status_pending     = $status_data['ids']['Pending']  ?? [];
    $status_waiting     = $status_data['ids']['Waiting']  ?? [];
    $status_negative    = $status_data['ids']['Negative'] ?? [];

    // 3. Definieer de Nederlandse variabelen (voor de logs hieronder)
    // Dit voorkomt 'Undefined variable' errors
    $status_positief    = $status_positive;
    $status_negatief    = $status_negative;
    
    // 'Misschien' is meestal een combinatie van Wachtend en Pending
    $status_misschien   = array_merge($status_pending, $status_waiting);
    sort($status_misschien);

    // ################################################################################################
    // # LOGGING (EXACT BEHOUDEN)
    // ################################################################################################

    wachthond($extdebug, 4, 'status_positive',  $status_positive);
    wachthond($extdebug, 4, 'status_pending',   $status_pending);
    wachthond($extdebug, 4, 'status_waiting',   $status_waiting);
    wachthond($extdebug, 4, 'status_negative',  $status_negative);

    wachthond($extdebug, 4, 'status_positief',  $status_positief);
    wachthond($extdebug, 4, 'status_negatief',  $status_negatief);
    wachthond($extdebug, 4, 'status_misschien', $status_misschien); 

    wachthond($extdebug, 4, "########################################################################");
    wachthond($extdebug, 4, "### CORE 0.3 VIND ALLE EVENT LEIDING & DEELNEMERS VOOR DIT JAAR");
    wachthond($extdebug, 4, "########################################################################");

    // 1. Haal alles op (uit cache of vers) met één aanroep
    $events_cache = find_eventids();

    // 2. Wijs toe aan lokale variabelen (direct uit de array)
    $kampids_deel       = $events_cache['deel']      ?? [];
    $kampids_leid       = $events_cache['leid']      ?? [];
    $kampids_meet       = $events_cache['meet']      ?? [];

    $kampids_deel_top   = $events_cache['deel_top']  ?? [];
    $kampids_deel_leid  = $events_cache['deel_leid'] ?? [];

    $kampids_deel_all   = $events_cache['deel_all']  ?? [];
    $kampids_leid_all   = $events_cache['leid_all']  ?? [];
    $kampids_all        = $events_cache['all']       ?? [];

    $kampids_test_deel  = $events_cache['deeltest']  ?? [];
    $kampids_test_leid  = $events_cache['leidtest']  ?? [];
    $kampids_test_all   = $events_cache['test_all']  ?? [];

    // 3. Logging
    wachthond($extdebug, 4, 'kampids_deel',       $kampids_deel);
    wachthond($extdebug, 4, 'kampids_leid',       $kampids_leid);    
    wachthond($extdebug, 4, 'kampids_meet',       $kampids_meet);    

    wachthond($extdebug, 4, 'kampids_deel_top',   $kampids_deel_top);
    wachthond($extdebug, 4, 'kampids_deel_leid',  $kampids_deel_leid);

    wachthond($extdebug, 4, 'kampids_deel_all',   $kampids_deel_all);
    wachthond($extdebug, 4, 'kampids_leid_all',   $kampids_leid_all);
    wachthond($extdebug, 4, 'kampids_all',        $kampids_all);

    wachthond($extdebug, 4, 'kampids_deel_test',  $kampids_test_deel);
    wachthond($extdebug, 4, 'kampids_leid_test',  $kampids_test_leid);
    wachthond($extdebug, 4, 'kampids_test_all',   $kampids_test_all);

    ###########################################################################################
    ### GET CONTACT_ID BASED ON ENTITY_ID
    ###########################################################################################

    if (in_array($groupID, $profilecontmax)) {  // PROFILE CV MAX
        $contact_id = $entityID;
    }

    ###########################################################################################
    ### GET PARTICIPANT INFO BASED ON PID
    ###########################################################################################

    // Initialiseer financiële variabelen — worden gevuld vanuit de pecunia extensie of
    // uit het participant-record. Zonder initialisatie worden NULL-waarden als "" naar DB geschreven.
    $ditevent_contribid          = NULL;
    $ditevent_lineitem_contribid = NULL;
    $saldo_bedrag                = NULL;
    $saldo_betaald               = NULL;
    $saldo_balans                = NULL;
    $params_contrib              = [];

    if (in_array($groupID, $profilepartmax)) {  // PROFILE PART MAX

        $part_id = $entityID;

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### CORE 1.0 GET PART INFO BASED ON PARTID",            "[PID: $part_id]");
        wachthond($extdebug,3, "########################################################################");

        # A. als partitipant wordt bewerkt dan zoek dan de participant info van dat event
        # B. indien participant die wordt bewerkt is gecancelled check of er een andere reg is die niet gecancceled is
        # C. indien contact wordt geedit (en geen participant), check dan  op eem actieve registratie dit fiscale jaar
        # D. indien dit allemaal niet het geval is, gewoon basic info ophalen voor het contact

        // 101  EVENT KENMERKEN
        // 139  PART DEEL
        // 190  PART LEID
        // (140 PART LEID VOG)
        // 106  TAB  WERVING
        // 103  TAB  CURRICULUM
        // 149  TAB  TALENT
        // 150  TAB  PROMOTIE
        // 205  PART 
        // 225  JAAROVERZICHT

        wachthond($extdebug,2, "pid2part met als input part_id (entityid)", "[PID: $part_id]"); 

        watchdog('civicrm_timing', base_microtimer("START pid2part"), NULL, WATCHDOG_DEBUG);
        $array_partditevent = base_pid2part($part_id);
        watchdog('civicrm_timing', base_microtimer("EINDE pid2part"), NULL, WATCHDOG_DEBUG);

        wachthond($extdebug,3, "########################################################################");
        wachthond($extdebug,2, "array_partditevent",                                  $array_partditevent);
        wachthond($extdebug,3, "########################################################################");

        $contact_id                         = $array_partditevent['contact_id']                     ?? NULL;
        $ditevent_displayname               = $array_partditevent['displayname']                    ?? NULL;

        $ditevent_part_contact_id           = $array_partditevent['contact_id']                     ?? NULL;
        $ditevent_part_eventid              = $array_partditevent['event_id']                       ?? NULL;
        $ditevent_part_id                   = $array_partditevent['id']                             ?? NULL;
        $ditevent_part_role_id              = $array_partditevent['role_id']                        ?? NULL;
        $ditevent_part_status_id            = $array_partditevent['status_id']                      ?? NULL;
        $ditevent_part_status_name          = $array_partditevent['status_name']                    ?? NULL;

        $ditevent_event_title               = $array_partditevent['event_title']                    ?? NULL;
        $ditevent_event_type_id             = $array_partditevent['event_type_id']                  ?? NULL;
        $ditevent_event_type_label          = $array_partditevent['event_type_label']               ?? NULL;

        $ditevent_register_date             = $array_partditevent['register_date']                  ?? NULL;
        $ditevent_event_start               = $array_partditevent['event_start_date']               ?? NULL;
        $ditevent_event_einde               = $array_partditevent['event_end_date']                 ?? NULL;
        $ditevent_kampjaar                  = $array_partditevent['part_kampjaar']                  ?? NULL;

        $ditevent_part_kampnaam             = $array_partditevent['part_kampnaam']                  ?? NULL;
        $ditevent_part_kampkort             = $array_partditevent['part_kampkort']                  ?? NULL;
        $ditevent_part_kampsoort            = $array_partditevent['part_kampsoort']                 ?? NULL;
        $ditevent_part_kampkort_low         = $array_partditevent['part_kampkort_low']              ?? NULL;
        $ditevent_part_kampkort_cap         = $array_partditevent['part_kampkort_cap']              ?? NULL;

        $ditevent_part_kamptype_id          = $array_partditevent['part_kamptype_id']               ?? NULL;
        $ditevent_part_functie              = $array_partditevent['part_functie']                   ?? NULL;
        $ditevent_part_rol                  = $array_partditevent['part_rol']                       ?? NULL;
        $ditevent_leid_welkkamp             = $array_partditevent['part_leid_kamp']                 ?? NULL;
        $ditevent_leid_functie              = $array_partditevent['part_leid_functie']              ?? NULL;

        // ==============================================================================
        // OVERRIDE: Logica voor Bestuur en Kampstaf (Hardcode kampkort en locatie)
        // ==============================================================================
        if ($ditevent_part_functie == 'bestuurslid' || $ditevent_leid_functie == 'bestuurslid' || strtoupper($ditevent_leid_welkkamp) == 'BESTUUR') {
            $ditevent_part_kampkort     = 'bst';
            $ditevent_leid_welkkamp     = 'bst';
            $ditevent_part_kampkort_cap = 'BST';
            $ditevent_part_kampkort_low = 'bst';
            $ditevent_part_rol          = 'bestuur'; 
            $ditevent_part_functie      = 'bestuurslid';
            
            wachthond($extdebug, 2, "OVERRIDE UITGEVOERD", "Kampkort overschreven naar 'bst'");

        } elseif ($ditevent_part_functie == 'kampstaf' || $ditevent_leid_functie == 'kampstaf' || strtoupper($ditevent_leid_welkkamp) == 'KAMPSTAF') {
            $ditevent_part_kampkort     = 'kst';
            $ditevent_leid_welkkamp     = 'kst';
            $ditevent_part_kampkort_cap = 'KST';
            $ditevent_part_kampkort_low = 'kst';
            $ditevent_part_rol          = 'kampstaf'; 
            $ditevent_part_functie      = 'kampstaf';
            
            wachthond($extdebug, 2, "OVERRIDE UITGEVOERD", "Kampkort overschreven naar 'kst'");
        }

        $ditevent_part_kampgeld_contribid   = $array_partditevent['part_kampgeld_contribid']        ?? NULL;
        $ditevent_part_kampgeld_regeling    = $array_partditevent['part_kampgeld_regeling']         ?? NULL;
        $ditevent_part_kampgeld_fietshuur   = $array_partditevent['part_kampgeld_fietshuur']        ?? NULL;

        // $ditevent_contribid is het aliased contribution ID dat door sectie 8+ gebruikt wordt
        $ditevent_contribid                 = $ditevent_part_kampgeld_contribid;

        $ditevent_part_1stdeel              = $array_partditevent['part_1stdeel']                   ?? NULL;
        $ditevent_part_1stleid              = $array_partditevent['part_1stleid']                   ?? NULL;

        $ditevent_part_nawgecheckt          = $array_partditevent['part_nawgecheckt']               ?? NULL;
        $ditevent_part_biogecheckt          = $array_partditevent['part_biogecheckt']               ?? NULL;

        $ditevent_part_groepklas            = $array_partditevent['part_groepklas']                 ?? NULL;
        $ditevent_part_voorkeur             = $array_partditevent['part_voorkeur']                  ?? NULL;
        $ditevent_part_groepsletter         = $array_partditevent['part_groepsletter']              ?? NULL;
        $ditevent_part_groepskleur          = $array_partditevent['part_groepskleur']               ?? NULL;
        $ditevent_part_groepsnaam           = $array_partditevent['part_groepsnaam']                ?? NULL;
        $ditevent_part_slaapzaal            = $array_partditevent['part_slaapzaal']                 ?? NULL;

        $ditevent_wachtlijst_erop           = $array_partditevent['wachtlijst_erop']                ?? NULL;
        $ditevent_wachtlijst_eraf           = $array_partditevent['wachtlijst_eraf']                ?? NULL;
        $ditevent_criteriacheck_start       = $array_partditevent['criteriacheck_start']            ?? NULL;
        $ditevent_criteriacheck_einde       = $array_partditevent['criteriacheck_einde']            ?? NULL;
        $ditevent_criteria_indicatie        = $array_partditevent['criteria_indicatie']             ?? NULL;
        $ditevent_criteria_oordeel          = $array_partditevent['criteria_oordeel']               ?? NULL;

        $ditevent_part_notificatie_deel     = $array_partditevent['part_notificatie_deel']          ?? NULL;
        $ditevent_part_notificatie_leid     = $array_partditevent['part_notificatie_leid']          ?? NULL;
        $ditevent_part_notificatie_kamp     = $array_partditevent['part_notificatie_kamp']          ?? NULL;
        $ditevent_part_notificatie_staf     = $array_partditevent['part_notificatie_staf']          ?? NULL;
        $ditevent_part_notificatie_priv     = $array_partditevent['part_notificatie_priv']          ?? NULL;

        $ditevent_part_kampgeld_contribid   = $array_partditevent['part_kampgeld_contribid']        ?? NULL;
        $ditevent_part_kampgeld_regeling    = $array_partditevent['part_kampgeld_regeling']         ?? NULL;
        $ditevent_part_kampgeld_fietshuur   = $array_partditevent['part_kampgeld_fietshuur']        ?? NULL;

        $ditevent_part_evaluatie_datum      = $array_partditevent['part_eval_datum']                ?? NULL;

        if (in_array($ditevent_event_type_id, $eventtypesleidall)) {
            $ditevent_part_vogverzocht      = $array_partditevent['part_vogverzoek']                ?? NULL;
            $ditevent_part_vogingediend     = $array_partditevent['part_vogaanvraag']               ?? NULL;
            $ditevent_part_vogontvangst     = $array_partditevent['part_vogontvangst']              ?? NULL;
            $ditevent_part_vogdatum         = $array_partditevent['part_vogdatum']                  ?? NULL;
            $ditevent_part_vogkenmerk       = $array_partditevent['part_vogkenmerk']                ?? NULL;
        }

        if ($ditevent_part_eventid > 0) {

            wachthond($extdebug,2, "########################################################################");
            wachthond($extdebug,1, "### CORE 1.1 GET EVENT INFO BASED ON EVENTID", "[EID: $ditevent_part_eventid / PID: $ditevent_part_id]");
            wachthond($extdebug,3, "########################################################################");

            $array_eventinfo_ditevent = base_eid2event($ditevent_part_eventid, $ditevent_part_id);

            wachthond($extdebug,4, "########################################################################");
            wachthond($extdebug,3, "array_eventinfo_ditevent",                     $array_eventinfo_ditevent);
            wachthond($extdebug,4, "########################################################################");

            $eventkamp_event_id             = $array_eventinfo_ditevent['eventkamp_event_id']           ?? NULL;
            $eventkamp_event_type_id        = $array_eventinfo_ditevent['eventkamp_event_type_id']      ?? NULL;
            $eventkamp_event_type_id_label  = $array_eventinfo_ditevent['eventkamp_event_type_id_label']?? NULL; 

            $eventkamp_kamptype_naam        = $array_eventinfo_ditevent['eventkamp_kamptype_naam']      ?? NULL;
            $eventkamp_kamptype_label       = $array_eventinfo_ditevent['eventkamp_kamptype_label']     ?? NULL;
            $eventkamp_kamptype_id          = $array_eventinfo_ditevent['eventkamp_kamptype_id']        ?? NULL;
            $eventkamp_kampsoort            = $array_eventinfo_ditevent['eventkamp_kampsoort']          ?? NULL;

            $eventkamp_kampnaam             = $array_eventinfo_ditevent['eventkamp_kampnaam']           ?? NULL;
            $eventkamp_kampkort             = $array_eventinfo_ditevent['eventkamp_kampkort']           ?? NULL;
            $eventkamp_kampkort_low         = $array_eventinfo_ditevent['eventkamp_kampkort_low']       ?? NULL;
            $eventkamp_kampkort_cap         = $array_eventinfo_ditevent['eventkamp_kampkort_cap']       ?? NULL;

            // ==============================================================================
            // OVERRIDE: Forceer Event Data voor Bestuur en Kampstaf (tbv DB Save in Sectie 8)
            // ==============================================================================
            if ($ditevent_part_rol == 'bestuur') {
                $eventkamp_kampkort = 'bst';
                $eventkamp_kampnaam = 'Bestuur';
                $eventkamp_pleklang = 'N.v.t.';
                $eventkamp_stadlang = 'N.v.t.';
            } elseif ($ditevent_part_rol == 'kampstaf') {
                $eventkamp_kampkort = 'kst';
                $eventkamp_kampnaam = 'Kampstaf';
                $eventkamp_pleklang = 'N.v.t.';
                $eventkamp_stadlang = 'N.v.t.';
            }

            $eventkamp_event_start          = $array_eventinfo_ditevent['eventkamp_event_start']        ?? NULL;
            $eventkamp_event_einde          = $array_eventinfo_ditevent['eventkamp_event_einde']        ?? NULL;
            $eventkamp_event_weeknr         = $array_eventinfo_ditevent['eventkamp_event_weeknr']       ?? NULL;

            $eventkamp_fiscalyear_start     = $array_eventinfo_ditevent['eventkamp_fiscalyear_start']   ?? NULL;
            $eventkamp_fiscalyear_einde     = $array_eventinfo_ditevent['eventkamp_fiscalyear_einde']   ?? NULL;
            $eventkamp_kampjaar             = $array_eventinfo_ditevent['eventkamp_kampjaar']           ?? NULL;
            $eventkamp_kampjaarkort         = $array_eventinfo_ditevent['eventkamp_kampjaarkort']       ?? NULL;

            $eventkamp_plek                 = $array_eventinfo_ditevent['eventkamp_plek']               ?? NULL;
            $eventkamp_stad                 = $array_eventinfo_ditevent['eventkamp_stad']               ?? NULL;
            $eventkamp_pleklang             = $array_eventinfo_ditevent['eventkamp_pleklang']           ?? NULL;
            $eventkamp_stadlang             = $array_eventinfo_ditevent['eventkamp_stadlang']           ?? NULL;

            $eventkamp_brengen_van          = $array_eventinfo_ditevent['eventkamp_brengen_van']        ?? NULL;
            $eventkamp_brengen_tot          = $array_eventinfo_ditevent['eventkamp_brengen_tot']        ?? NULL;
            $eventkamp_pres_van             = $array_eventinfo_ditevent['eventkamp_pres_van']           ?? NULL;
            $eventkamp_pres_tot             = $array_eventinfo_ditevent['eventkamp_pres_tot']           ?? NULL;
            $eventkamp_halen_van            = $array_eventinfo_ditevent['eventkamp_halen_van']          ?? NULL;
            $eventkamp_halen_tot            = $array_eventinfo_ditevent['eventkamp_halen_tot']          ?? NULL;

            $eventkamp_thema_naam           = $array_eventinfo_ditevent['eventkamp_thema_naam']         ?? NULL;
            $eventkamp_thema_info           = $array_eventinfo_ditevent['eventkamp_thema_info']         ?? NULL;
            $eventkamp_goeddoel_naam        = $array_eventinfo_ditevent['eventkamp_goeddoel_naam']      ?? NULL;
            $eventkamp_goeddoel_info        = $array_eventinfo_ditevent['eventkamp_goeddoel_info']      ?? NULL;
            $eventkamp_goeddoel_link        = $array_eventinfo_ditevent['eventkamp_goeddoel_link']      ?? NULL;

            $eventkamp_welkomvideo          = $array_eventinfo_ditevent['eventkamp_welkomvideo']        ?? NULL;
            $eventkamp_slotvideo            = $array_eventinfo_ditevent['eventkamp_slotvideo']          ?? NULL;
            $eventkamp_extrabagage          = $array_eventinfo_ditevent['eventkamp_extrabagage']        ?? NULL;
            $eventkamp_playlist             = $array_eventinfo_ditevent['eventkamp_playlist']           ?? NULL;
            $eventkamp_doc_link             = $array_eventinfo_ditevent['eventkamp_doc_link']           ?? NULL;
            $eventkamp_doc_info             = $array_eventinfo_ditevent['eventkamp_doc_info']           ?? NULL;
            $eventkamp_foto_vraag           = $array_eventinfo_ditevent['eventkamp_foto_vraag']         ?? NULL;
            $eventkamp_foto_album           = $array_eventinfo_ditevent['eventkamp_foto_album']         ?? NULL;
        
            $eventkamp_event_hldn1_id       = $array_eventinfo_ditevent['event_hldn1_id']               ?? NULL;
            $eventkamp_event_hldn2_id       = $array_eventinfo_ditevent['event_hldn2_id']               ?? NULL;
            $eventkamp_event_hldn3_id       = $array_eventinfo_ditevent['event_hldn3_id']               ?? NULL;

            $eventkamp_event_kern1_id       = $array_eventinfo_ditevent['event_kern1_id']               ?? NULL;
            $eventkamp_event_kern2_id       = $array_eventinfo_ditevent['event_kern2_id']               ?? NULL;
            $eventkamp_event_kern3_id       = $array_eventinfo_ditevent['event_kern3_id']               ?? NULL;

            $eventkamp_event_gedrag0_id     = $array_eventinfo_ditevent['event_gedrag0_id']             ?? NULL;
            $eventkamp_event_gedrag1_id     = $array_eventinfo_ditevent['event_gedrag1_id']             ?? NULL;
            $eventkamp_event_gedrag2_id     = $array_eventinfo_ditevent['event_gedrag2_id']             ?? NULL;

            $eventkamp_event_keuken0_id     = $array_eventinfo_ditevent['event_keuken0_id']             ?? NULL;
            $eventkamp_event_keuken1_id     = $array_eventinfo_ditevent['event_keuken1_id']             ?? NULL;
            $eventkamp_event_keuken2_id     = $array_eventinfo_ditevent['event_keuken2_id']             ?? NULL;
            $eventkamp_event_keuken3_id     = $array_eventinfo_ditevent['event_keuken3_id']             ?? NULL;

            $eventkamp_event_boekje0_id     = $array_eventinfo_ditevent['event_boekje0_id']             ?? NULL;
            $eventkamp_event_boekje1_id     = $array_eventinfo_ditevent['event_boekje1_id']             ?? NULL;
            $eventkamp_event_boekje2_id     = $array_eventinfo_ditevent['event_boekje2_id']             ?? NULL;

            // --- 2. BOUW DE ROLLEN ARRAY VOOR ACL ---
            // Deze array geven we door aan de ACL module.
            $ditevent_rollen_array = array(
                'event_hldn1_id'    => $eventkamp_event_hldn1_id,
                'event_hldn2_id'    => $eventkamp_event_hldn2_id,
                'event_hldn3_id'    => $eventkamp_event_hldn3_id,

                'event_kern1_id'    => $eventkamp_event_kern1_id,
                'event_kern2_id'    => $eventkamp_event_kern2_id,
                'event_kern3_id'    => $eventkamp_event_kern3_id,

                'event_gedrag0_id'  => $eventkamp_event_gedrag0_id,
                'event_gedrag1_id'  => $eventkamp_event_gedrag1_id,
                'event_gedrag2_id'  => $eventkamp_event_gedrag2_id,

                'event_keuken0_id'  => $eventkamp_event_keuken0_id,
                'event_keuken1_id'  => $eventkamp_event_keuken1_id,
                'event_keuken2_id'  => $eventkamp_event_keuken2_id,
                'event_keuken3_id'  => $eventkamp_event_keuken3_id,

                'event_boekje0_id'  => $eventkamp_event_boekje0_id,
                'event_boekje1_id'  => $eventkamp_event_boekje1_id,
                'event_boekje2_id'  => $eventkamp_event_boekje2_id,
            );

            wachthond($extdebug,3, 'ditevent_rollen_array', $ditevent_rollen_array);

        }

        // --- DEEL 1.2: GET CONTACT INFO HOOFDLEIDING ---
        if ($ditevent_part_eventid > 0) {

            wachthond($extdebug,2, "########################################################################");
            wachthond($extdebug,1, "### CORE 1.2 GET CONTACT INFO HOOFDLEIDING VAN DIT EVENT","[EID: $eventkamp_event_id]");
            wachthond($extdebug,3, "########################################################################");

            wachthond($extdebug,2, 'eventkamp_hoofdleid1_id',         $eventkamp_event_hldn1_id);
            wachthond($extdebug,2, 'eventkamp_hoofdleid2_id',         $eventkamp_event_hldn2_id);
            wachthond($extdebug,2, 'eventkamp_hoofdleid3_id',         $eventkamp_event_hldn3_id);

            if ($eventkamp_event_hldn1_id > 0) {
                $hldn1_info_array = base_find_hldn_info($eventkamp_event_hldn1_id);
                wachthond($extdebug,2, "hldn1_info_array",  $hldn1_info_array);
                $event_hoofdleiding1_displname = $hldn1_info_array['event_hoofdleiding_displname']  ?? NULL;
                $event_hoofdleiding1_firstname = $hldn1_info_array['event_hoofdleiding_firstname']  ?? NULL;
                $event_hoofdleiding1_image     = $hldn1_info_array['event_hoofdleiding_image']      ?? NULL;
                $event_hoofdleiding1_image_bn  = $hldn1_info_array['event_hoofdleiding_image_bn']   ?? NULL;
                $event_hoofdleiding1_phone     = $hldn1_info_array['event_hoofdleiding_phone']      ?? NULL;
                $event_hoofdleiding1_dontphone = $hldn1_info_array['event_hoofdleiding_dontphone']  ?? NULL;
            }

            if ($eventkamp_event_hldn2_id > 0) {
                $hldn2_info_array = base_find_hldn_info($eventkamp_event_hldn2_id);
                wachthond($extdebug,2, "hldn2_info_array",  $hldn2_info_array);
                $event_hoofdleiding2_displname = $hldn2_info_array['event_hoofdleiding_displname']  ?? NULL;
                $event_hoofdleiding2_firstname = $hldn2_info_array['event_hoofdleiding_firstname']  ?? NULL;
                $event_hoofdleiding2_image     = $hldn2_info_array['event_hoofdleiding_image']      ?? NULL;
                $event_hoofdleiding2_image_bn  = $hldn2_info_array['event_hoofdleiding_image_bn']   ?? NULL;
                $event_hoofdleiding2_phone     = $hldn2_info_array['event_hoofdleiding_phone']      ?? NULL;
                $event_hoofdleiding2_dontphone = $hldn2_info_array['event_hoofdleiding_dontphone']  ?? NULL;
            }

            if ($eventkamp_event_hldn3_id > 0) {
                $hldn3_info_array = base_find_hldn_info($eventkamp_event_hldn3_id);
                wachthond($extdebug,2, "hldn3_info_array",  $hldn3_info_array);
                $event_hoofdleiding3_displname = $hldn3_info_array['event_hoofdleiding_displname']  ?? NULL;
                $event_hoofdleiding3_firstname = $hldn3_info_array['event_hoofdleiding_firstname']  ?? NULL;
                $event_hoofdleiding3_image     = $hldn3_info_array['event_hoofdleiding_image']      ?? NULL;
                $event_hoofdleiding3_image_bn  = $hldn3_info_array['event_hoofdleiding_image_bn']   ?? NULL;
                $event_hoofdleiding3_phone     = $hldn3_info_array['event_hoofdleiding_phone']      ?? NULL;
                $event_hoofdleiding3_dontphone = $hldn3_info_array['event_hoofdleiding_dontphone']  ?? NULL;
            }
        }
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 1.4 BEPAAL HET CONTACT ID",             "[EntityID: $entityID]");
    wachthond($extdebug,3, "########################################################################");

    ##########################################################################################
    ### IF NO CONTACTID
    ##########################################################################################

    if (empty($contact_id)) {
        wachthond($extdebug,1, "CONTACTID - ERROR : CONTACT INFO LEEG > RETURN",    $array_partditevent[0]);
        return; //    if not, get out of here
    } else {
        wachthond($extdebug,3, "CONTACTID: ER IS EEN WAARDE GEVONDEN",              "$contact_id [PRIMA]");
    }

    ##########################################################################################
    ### IF CONTACTID == PARTID
    ##########################################################################################

    if ($contact_id > 0 AND $ditevent_part_id > 0 AND $contact_id == $ditevent_part_id) {
        wachthond($extdebug,1, "CONTACTID - ERROR CONTACTID ($contact_id) == PART_ID ($ditevent_part_id)", "[RETURN!]");
        wachthond($extdebug,1, "ERROR : HEEL UITZONDERLIJK - WELLICHT ANDERE OORZAAK",    $array_partditevent);
        return; //    if not, get out of here
    } else {
        wachthond($extdebug,3, "CONTACTID IS NOT THE SAME AS THE PARTID",           "$contact_id [PRIMA]");
    }

    ########################################################################
    # CORE 1.3 GET CONTACT INFO BASED ON CONTACTID
    # We controleren expliciet op een numerieke ID om TypeErrors in PHP 8+ te voorkomen.
    # base_cid2cont verwacht strikt een integer.
    ########################################################################
    
    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CORE 1.5 GET CONTACT INFO BASED ON CONTACTID", "[CID: " . ($contact_id ?? 'NULL') . "]");
    wachthond($extdebug, 3, "########################################################################");

    // Initialiseer variabelen om 'undefined' notices te voorkomen
    $array_contditjaar = NULL;

    if (!empty($contact_id) && is_numeric($contact_id)) {
        
        watchdog('civicrm_timing', base_microtimer("START cid2cont"), NULL, WATCHDOG_DEBUG);
        
        // Voer de conversie uit van CID naar uitgebreide contact array
        $array_contditjaar = base_cid2cont((int) $contact_id);
        
        wachthond($extdebug, 3, "array_contditjaar", $array_contditjaar);
        watchdog('civicrm_timing', base_microtimer("EINDE cid2cont"), NULL, WATCHDOG_DEBUG);
        
    } else {
        // Loggen dat we de aanroep overslaan omdat de ID ontbreekt (bijv. bij nieuwe, nog niet opgeslagen contacten)
        wachthond($extdebug, 1, "SKIPPED: base_cid2cont overgeslagen want contact_id is leeg.", "[CORE-SKIP]");
    }

    $contact_foto               = $array_contditjaar['contact_foto']            ?? NULL;
    $birth_date                 = $array_contditjaar['birth_date']              ?? NULL;
    $geslacht                   = $array_contditjaar['gender']                  ?? NULL;
    $first_name                 = $array_contditjaar['first_name']              ?? NULL;
    $middle_name                = $array_contditjaar['middle_name']             ?? NULL;
    $last_name                  = $array_contditjaar['last_name']               ?? NULL;    
    $nick_name                  = $array_contditjaar['nick_name']               ?? NULL;
    $displayname                = $array_contditjaar['displayname']             ?? NULL;
    $crm_drupalnaam             = $array_contditjaar['crm_drupalnaam']          ?? NULL;    // drupal username
    $crm_externalid             = $array_contditjaar['crm_externalid']          ?? NULL;    // drupal cmsid

    $laatste_keer               = $array_contditjaar['laatstekeer']             ?? NULL;    // M61: tbv berekenen jaar 'mee komend jaar'       
    $curcv_deel_array           = $array_contditjaar['curcv_deel_array']        ?? NULL;    // welke jaren deel
    $curcv_leid_array           = $array_contditjaar['curcv_leid_array']        ?? NULL;    // welke jaren leid 
    $oldcv_deel_array           = $array_contditjaar['oldcv_deel_array']        ?? NULL;    // welke jaren deel OUD
    $oldcv_leid_array           = $array_contditjaar['oldcv_leid_array']        ?? NULL;    // welke jaren leid OUD
    $curcv_keer_deel            = $array_contditjaar['curcv_keer_deel']         ?? NULL;    // keren deel
    $curcv_keer_leid            = $array_contditjaar['curcv_keer_leid']         ?? NULL;    // keren leid

    $datum_belangstelling       = $array_contditjaar['datum_belangstelling']    ?? NULL;
    $intakegesprekdatum         = $array_contditjaar['cont_intake_datum']       ?? NULL;
    $intakegesprekpersoon       = $array_contditjaar['cont_intake_persoon']     ?? NULL;

    $werving_mee_komendkamp     = $array_contditjaar['werving_mee_komendkamp']  ?? NULL;
    $werving_mee_verwachting    = $array_contditjaar['werving_mee_verwachting'] ?? NULL;
    $werving_mee_toelichting    = $array_contditjaar['werving_mee_toelichting'] ?? NULL;
    $werving_mee_update         = $array_contditjaar['werving_mee_update']      ?? NULL;
    $werving_mee_update_year    = $array_contditjaar['werving_mee_update_year'] ?? NULL;
    $werving_mee_notities       = $array_contditjaar['werving_mee_notities']    ?? NULL;
    $werving_vakantieregio      = $array_contditjaar['werving_vakantieregio']   ?? NULL;   

    $cont_fotstatus             = $array_contditjaar['cont_fotstatus']          ?? NULL;
    $cont_fotupdate             = $array_contditjaar['cont_fotupdate']          ?? NULL;

    $ditjaar_nawgecheckt        = $array_contditjaar['cont_nawgecheckt']        ?? NULL;
    $ditjaar_bioingevuld        = $array_contditjaar['cont_bioingevuld']        ?? NULL;
    $ditjaar_biogecheckt        = $array_contditjaar['cont_biogecheckt']        ?? NULL;

    $ditjaar_nawstatus          = $array_contditjaar['cont_nawstatus']          ?? NULL;
    $ditjaar_biostatus          = $array_contditjaar['cont_biostatus']          ?? NULL;

    $org_ditjaar_nawgecheckt    = $ditjaar_nawgecheckt;
    $new_ditjaar_nawgecheckt    = $ditjaar_nawgecheckt;
    $org_ditjaar_biogecheckt    = $ditjaar_biogecheckt;
    $new_ditjaar_biogecheckt    = $ditjaar_biogecheckt;

    $org_ditjaar_nawstatus      = $ditjaar_nawstatus;
    $new_ditjaar_nawstatus      = $ditjaar_nawstatus;
    $org_ditjaar_biostatus      = $ditjaar_biostatus;
    $new_ditjaar_biostatus      = $ditjaar_biostatus;

    $privacy_voorkeuren         = $array_contditjaar['privacy_voorkeuren']      ?? NULL;  // bv. Verwijder contactgegevens
    $privacy_geheimadres        = $array_contditjaar['privacy_geheimadres']     ?? NULL;  // bv. Ja / Nee
    $privacy_beeldgebruik       = $array_contditjaar['privacy_beeldgebruik']    ?? NULL;  // Ik geef toestemming voor kampfotos

    $cont_notificatie_deel      = $array_contditjaar['cont_notificatie_deel']   ?? NULL;
    $cont_notificatie_leid      = $array_contditjaar['cont_notificatie_leid']   ?? NULL;
    $cont_notificatie_kamp      = $array_contditjaar['cont_notificatie_kamp']   ?? NULL;
    $cont_notificatie_staf      = $array_contditjaar['cont_notificatie_staf']   ?? NULL;

    /*
        $results = civicrm_api4('Note', 'create', [
            'values' => [
              'entity_table' => 'civicrm_contact',        
              'entity_id' => 27,
            'contact_id' => 1,          
              'note' => 'DIT IS EEN TEST',
              'subject' => 'testonderwerp',
              'note_date' => '2024-05-01',
              'privacy' => 2,
            ],
            'checkPermissions' => TRUE,
        ]);
    */

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 1.6 a CV DEEP DIVE CHECK DIT JAAR DEEL/LEID POS/ONE", "[partditjaar_all]");
    wachthond($extdebug,2, "########################################################################");

    watchdog('civicrm_timing', base_microtimer("START allpart"), NULL, WATCHDOG_DEBUG);
    $array_allpart_ditjaar              = base_find_allpart($contact_id, $today_datetime) ?: [];
    watchdog('civicrm_timing', base_microtimer("EINDE allpart"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,2, "array_allpart_ditjaar",                            $array_allpart_ditjaar);
    wachthond($extdebug,4, "########################################################################");

    $ditjaar_refdate                    = $array_allpart_ditjaar['refdate'];
    $ditjaar_refyear                    = $array_allpart_ditjaar['refyear'];

    $ditjaar_all_count                  = $array_allpart_ditjaar['result_allpart_all_count'];
    $ditjaar_pen_count                  = $array_allpart_ditjaar['result_allpart_pen_count'];
    $ditjaar_wait_count                 = $array_allpart_ditjaar['result_allpart_wait_count'];
    $ditjaar_neg_count                  = $array_allpart_ditjaar['result_allpart_neg_count'];

    $ditjaar_pos_count                  = $array_allpart_ditjaar['result_allpart_pos_count'];
    $ditjaar_pos_deel_count             = $array_allpart_ditjaar['result_allpart_pos_deel_count'];
    $ditjaar_pos_leid_count             = $array_allpart_ditjaar['result_allpart_pos_leid_count'];

    $ditjaar_all_count                  = $array_allpart_ditjaar['result_allpart_all_count'];
    $ditjaar_all_deel_count             = $array_allpart_ditjaar['result_allpart_all_deel_count'];
    $ditjaar_all_leid_count             = $array_allpart_ditjaar['result_allpart_all_leid_count'];

    $ditjaar_one_part_id                = $array_allpart_ditjaar['result_allpart_one_part_id'];
    $ditjaar_one_deel_part_id           = $array_allpart_ditjaar['result_allpart_one_deel_part_id'];
    $ditjaar_one_leid_part_id           = $array_allpart_ditjaar['result_allpart_one_leid_part_id'];

    $ditjaar_one_event_id               = $array_allpart_ditjaar['result_allpart_one_event_id'];
    $ditjaar_one_deel_event_id          = $array_allpart_ditjaar['result_allpart_one_deel_event_id'];
    $ditjaar_one_leid_event_id          = $array_allpart_ditjaar['result_allpart_one_leid_event_id'];

    $ditjaar_one_event_type_id          = $array_allpart_ditjaar['result_allpart_one_event_type_id'];
    $ditjaar_one_deel_event_type_id     = $array_allpart_ditjaar['result_allpart_one_deel_event_type_id'];
    $ditjaar_one_leid_event_type_id     = $array_allpart_ditjaar['result_allpart_one_leid_event_type_id'];

    $ditjaar_one_status_id              = $array_allpart_ditjaar['result_allpart_one_status_id'];
    $ditjaar_one_deel_status_id         = $array_allpart_ditjaar['result_allpart_one_deel_status_id'];
    $ditjaar_one_leid_status_id         = $array_allpart_ditjaar['result_allpart_one_leid_status_id'];

    $ditjaar_one_kampfunctie            = $array_allpart_ditjaar['result_allpart_one_kampfunctie'];
    $ditjaar_one_deel_kampfunctie       = $array_allpart_ditjaar['result_allpart_one_deel_kampfunctie'];
    $ditjaar_one_leid_kampfunctie       = $array_allpart_ditjaar['result_allpart_one_leid_kampfunctie'];

    $ditjaar_one_kampkort               = $array_allpart_ditjaar['result_allpart_one_kampkort'];
    $ditjaar_one_deel_kampkort          = $array_allpart_ditjaar['result_allpart_one_deel_kampkort'];
    $ditjaar_one_leid_kampkort          = $array_allpart_ditjaar['result_allpart_one_leid_kampkort'];

    $ditjaar_pos_part_id                = $array_allpart_ditjaar['result_allpart_pos_part_id'];
    $ditjaar_pos_deel_part_id           = $array_allpart_ditjaar['result_allpart_pos_deel_part_id'];
    $ditjaar_pos_leid_part_id           = $array_allpart_ditjaar['result_allpart_pos_leid_part_id'];

    $ditjaar_pos_event_id               = $array_allpart_ditjaar['result_allpart_pos_event_id'];
    $ditjaar_pos_deel_event_id          = $array_allpart_ditjaar['result_allpart_pos_deel_event_id'];
    $ditjaar_pos_leid_event_id          = $array_allpart_ditjaar['result_allpart_pos_leid_event_id'];

    $ditjaar_pos_event_type_id          = $array_allpart_ditjaar['result_allpart_pos_event_type_id'];
    $ditjaar_pos_deel_event_type_id     = $array_allpart_ditjaar['result_allpart_pos_deel_event_type_id'];
    $ditjaar_pos_leid_event_type_id     = $array_allpart_ditjaar['result_allpart_pos_leid_event_type_id'];

    $ditjaar_pos_status_id              = $array_allpart_ditjaar['result_allpart_pos_status_id'];
    $ditjaar_pos_deel_status_id         = $array_allpart_ditjaar['result_allpart_pos_deel_status_id'];
    $ditjaar_pos_leid_status_id         = $array_allpart_ditjaar['result_allpart_pos_leid_status_id'];

    $ditjaar_pos_kampfunctie            = $array_allpart_ditjaar['result_allpart_pos_kampfunctie'];
    $ditjaar_pos_deel_kampfunctie       = $array_allpart_ditjaar['result_allpart_pos_deel_kampfunctie'];
    $ditjaar_pos_leid_kampfunctie       = $array_allpart_ditjaar['result_allpart_pos_leid_kampfunctie'];

    $ditjaar_pos_kampkort               = $array_allpart_ditjaar['result_allpart_pos_kampkort'];
    $ditjaar_pos_deel_kampkort          = $array_allpart_ditjaar['result_allpart_pos_deel_kampkort'];
    $ditjaar_pos_leid_kampkort          = $array_allpart_ditjaar['result_allpart_pos_leid_kampkort'];

    $ditjaar_wait_part_id               = $array_allpart_ditjaar['result_allpart_wait_part_id'];
    $ditjaar_pen_part_id                = $array_allpart_ditjaar['result_allpart_pen_part_id'];
    $ditjaar_neg_part_id                = $array_allpart_ditjaar['result_allpart_neg_part_id']       ?? NULL;

    $ditjaar_wait_event_id              = $array_allpart_ditjaar['result_allpart_wait_event_id'];
    $ditjaar_pen_event_id               = $array_allpart_ditjaar['result_allpart_pen_event_id'];
    $ditjaar_neg_event_id               = $array_allpart_ditjaar['result_allpart_neg_event_id']      ?? NULL;

    $ditjaar_wait_event_type_id         = $array_allpart_ditjaar['result_allpart_wait_event_type_id'];
    $ditjaar_pen_event_type_id          = $array_allpart_ditjaar['result_allpart_pen_event_type_id'];
    $ditjaar_neg_event_type_id          = $array_allpart_ditjaar['result_allpart_neg_event_type_id'] ?? NULL;

    $ditjaar_wait_status_id             = $array_allpart_ditjaar['result_allpart_wait_status_id'];
    $ditjaar_pen_status_id              = $array_allpart_ditjaar['result_allpart_pen_status_id'];
    $ditjaar_neg_status_id              = $array_allpart_ditjaar['result_allpart_neg_status_id']     ?? NULL;

    $ditjaar_wait_kampkort              = $array_allpart_ditjaar['result_allpart_wait_kampkort'];
    $ditjaar_pen_kampkort               = $array_allpart_ditjaar['result_allpart_pen_kampkort'];
    $ditjaar_neg_kampkort               = $array_allpart_ditjaar['result_allpart_neg_kampkort']      ?? NULL;

    if ($ditjaar_pos_count       == 1) { ### 1 POS ALL
        wachthond($extdebug,2,  "DIT JAAR: UIT $ditjaar_all_count PARTICIPANTS $ditjaar_pos_count POSITIEVE DEELNAME GEVONDEN [D/L]","$ditjaar_pos_part_id ($ditjaar_pos_kampkort $ditjaar_refyear)");
    }
    if ($ditjaar_pos_deel_count  == 1) { ### 1 POS DEEL
        wachthond($extdebug,2,  "DIT JAAR: $ditjaar_pos_deel_count POSITIEVE DEELNAME GEVONDEN (UIT $ditjaar_all_count) [DEEL]","$ditjaar_pos_deel_part_id ($ditjaar_pos_deel_kampkort $ditjaar_refyear)");
    }
    if ($ditjaar_pos_leid_count  == 1) { ### 1 POS LEID
        wachthond($extdebug,2,  "DIT JAAR: $ditjaar_pos_leid_count POSITIEVE DEELNAME GEVONDEN UIT ($ditjaar_all_count) [LEID] ","$ditjaar_pos_leid_part_id ($ditjaar_pos_leid_kampkort $ditjaar_refyear)");
    }

    if ($ditjaar_all_count       == 1) { ### 1 ONE ALL
        wachthond($extdebug,2,  "DIT JAAR: PRECIES ONE DEELNAME RECORD GEVONDEN [D/L]",
                                "$ditjaar_one_part_id ($ditjaar_one_kampkort $ditjaar_refyear)");
    }
    if ($ditjaar_all_deel_count  == 1) { ### 1 ONE DEEL
        wachthond($extdebug,2,  "DIT JAAR: PRECIES ONE DEELNAME RECORD GEVONDEN [DEEL]",
                                "$ditjaar_one_deel_part_id ($ditjaar_one_deel_kampkort $ditjaar_refyear)");
    }
    if ($ditjaar_all_leid_count  == 1) { ### 1 ONE LEID
        wachthond($extdebug,2,  "DIT JAAR: PRECIES ONE DEELNAME RECORD GEVONDEN [LEID]",
                                "$ditjaar_one_leid_part_id ($ditjaar_one_leid_kampkort $ditjaar_refyear)");
    }        

    $array_allpart_eventjaar            = base_find_allpart($contact_id, $ditevent_event_start) ?: [];

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,2, "array_allpart_eventjaar",                        $array_allpart_eventjaar);
    wachthond($extdebug,3, "########################################################################");

    $eventjaar_refdate                  = $array_allpart_eventjaar['refdate'];
    $eventjaar_refyear                  = $array_allpart_eventjaar['refyear'];

    $eventjaar_all_count                = $array_allpart_eventjaar['result_allpart_all_count'];
    $eventjaar_pen_count                = $array_allpart_eventjaar['result_allpart_pen_count'];
    $eventjaar_wait_count               = $array_allpart_eventjaar['result_allpart_wait_count'];
    $eventjaar_neg_count                = $array_allpart_eventjaar['result_allpart_neg_count'];

    $eventjaar_pos_count                = $array_allpart_eventjaar['result_allpart_pos_count'];
    $eventjaar_pos_deel_count           = $array_allpart_eventjaar['result_allpart_pos_deel_count'];
    $eventjaar_pos_leid_count           = $array_allpart_eventjaar['result_allpart_pos_leid_count'];

    $eventjaar_all_count                = $array_allpart_eventjaar['result_allpart_all_count'];
    $eventjaar_all_deel_count           = $array_allpart_eventjaar['result_allpart_all_deel_count'];
    $eventjaar_all_leid_count           = $array_allpart_eventjaar['result_allpart_all_leid_count'];

    $eventjaar_one_part_id              = $array_allpart_eventjaar['result_allpart_one_part_id'];
    $eventjaar_one_deel_part_id         = $array_allpart_eventjaar['result_allpart_one_deel_part_id'];
    $eventjaar_one_leid_part_id         = $array_allpart_eventjaar['result_allpart_one_leid_part_id'];

    $eventjaar_one_event_id             = $array_allpart_eventjaar['result_allpart_one_event_id'];
    $eventjaar_one_deel_event_id        = $array_allpart_eventjaar['result_allpart_one_deel_event_id'];
    $eventjaar_one_leid_event_id        = $array_allpart_eventjaar['result_allpart_one_leid_event_id'];

    $eventjaar_one_event_type_id        = $array_allpart_eventjaar['result_allpart_one_event_type_id'];
    $eventjaar_one_deel_event_type_id   = $array_allpart_eventjaar['result_allpart_one_deel_event_type_id'];
    $eventjaar_one_leid_event_type_id   = $array_allpart_eventjaar['result_allpart_one_leid_event_type_id'];

    $eventjaar_one_status_id            = $array_allpart_eventjaar['result_allpart_one_status_id'];
    $eventjaar_one_deel_status_id       = $array_allpart_eventjaar['result_allpart_one_deel_status_id'];
    $eventjaar_one_leid_status_id       = $array_allpart_eventjaar['result_allpart_one_leid_status_id'];

    $eventjaar_one_kampkort             = $array_allpart_eventjaar['result_allpart_one_kampkort'];
    $eventjaar_one_deel_kampkort        = $array_allpart_eventjaar['result_allpart_one_deel_kampkort'];
    $eventjaar_one_leid_kampkort        = $array_allpart_eventjaar['result_allpart_one_leid_kampkort'];

    $eventjaar_pos_part_id              = $array_allpart_eventjaar['result_allpart_pos_part_id'];
    $eventjaar_pos_deel_part_id         = $array_allpart_eventjaar['result_allpart_pos_deel_part_id'];
    $eventjaar_pos_leid_part_id         = $array_allpart_eventjaar['result_allpart_pos_leid_part_id'];

    $eventjaar_pos_event_id             = $array_allpart_eventjaar['result_allpart_pos_event_id'];
    $eventjaar_pos_deel_event_id        = $array_allpart_eventjaar['result_allpart_pos_deel_event_id'];
    $eventjaar_pos_leid_event_id        = $array_allpart_eventjaar['result_allpart_pos_leid_event_id'];

    $eventjaar_pos_event_type_id        = $array_allpart_eventjaar['result_allpart_pos_event_type_id'];
    $eventjaar_pos_deel_event_type_id   = $array_allpart_eventjaar['result_allpart_pos_deel_event_type_id'];
    $eventjaar_pos_leid_event_type_id   = $array_allpart_eventjaar['result_allpart_pos_leid_event_type_id'];

    $eventjaar_pos_status_id            = $array_allpart_eventjaar['result_allpart_pos_status_id'];
    $eventjaar_pos_deel_status_id       = $array_allpart_eventjaar['result_allpart_pos_deel_status_id'];
    $eventjaar_pos_leid_status_id       = $array_allpart_eventjaar['result_allpart_pos_leid_status_id'];

    $eventjaar_pos_kampkort             = $array_allpart_eventjaar['result_allpart_pos_kampkort'];
    $eventjaar_pos_deel_kampkort        = $array_allpart_eventjaar['result_allpart_pos_deel_kampkort'];
    $eventjaar_pos_leid_kampkort        = $array_allpart_eventjaar['result_allpart_pos_leid_kampkort'];

    $eventjaar_wait_part_id             = $array_allpart_eventjaar['result_allpart_wait_part_id'];
    $eventjaar_pen_part_id              = $array_allpart_eventjaar['result_allpart_pen_part_id'];
    $eventjaar_neg_part_id              = $array_allpart_eventjaar['result_allpart_neg_part_id']       ?? NULL;

    $eventjaar_wait_event_id            = $array_allpart_eventjaar['result_allpart_wait_event_id'];
    $eventjaar_pen_event_id             = $array_allpart_eventjaar['result_allpart_pen_event_id'];
    $eventjaar_neg_event_id             = $array_allpart_eventjaar['result_allpart_neg_event_id']      ?? NULL;

    $eventjaar_wait_event_type_id       = $array_allpart_eventjaar['result_allpart_wait_event_type_id'];
    $eventjaar_pen_event_type_id        = $array_allpart_eventjaar['result_allpart_pen_event_type_id'];
    $eventjaar_neg_event_type_id        = $array_allpart_eventjaar['result_allpart_neg_event_type_id'] ?? NULL;

    $eventjaar_wait_status_id           = $array_allpart_eventjaar['result_allpart_wait_status_id'];
    $eventjaar_pen_status_id            = $array_allpart_eventjaar['result_allpart_pen_status_id'];
    $eventjaar_neg_status_id            = $array_allpart_eventjaar['result_allpart_neg_status_id']     ?? NULL;

    $eventjaar_wait_kampkort            = $array_allpart_eventjaar['result_allpart_wait_kampkort'];
    $eventjaar_pen_kampkort             = $array_allpart_eventjaar['result_allpart_pen_kampkort'];
    $eventjaar_neg_kampkort             = $array_allpart_eventjaar['result_allpart_neg_kampkort']      ?? NULL;

    if ($eventjaar_pos_count       == 1) { ### 1 POS ALL
        wachthond($extdebug,2,  "DIT EVENTJAAR: UIT $eventjaar_all_count PARTICIPANTS $eventjaar_pos_count POSITIEVE DEELNAME GEVONDEN [D/L]","$eventjaar_pos_part_id ($eventjaar_pos_kampkort $eventjaar_refyear)");
    }
    if ($eventjaar_pos_deel_count  == 1) { ### 1 POS DEEL
        wachthond($extdebug,2,  "DIT EVENTJAAR: $eventjaar_pos_deel_count POSITIEVE DEELNAME GEVONDEN (UIT $eventjaar_all_count) [DEEL]","$eventjaar_pos_deel_part_id ($eventjaar_pos_deel_kampkort $eventjaar_refyear)");
    }
    if ($eventjaar_pos_leid_count  == 1) { ### 1 POS LEID
        wachthond($extdebug,2,  "DIT EVENTJAAR: $eventjaar_pos_leid_count POSITIEVE DEELNAME GEVONDEN (UIT $eventjaar_all_count [LEID])","$eventjaar_pos_leid_part_id ($eventjaar_pos_leid_kampkort $eventjaar_refyear)");
    }

    if ($eventjaar_all_count       == 1) { ### 1 ONE ALL
        wachthond($extdebug,2,  "DIT EVENTJAAR: ER IS PRECIES ONE DEELNAME RECORD GEVONDEN [D/L]",
                                "$eventjaar_one_part_id ($eventjaar_one_kampkort $eventjaar_refyear)");
    }
    if ($eventjaar_all_deel_count  == 1) { ### 1 ONE DEEL
        wachthond($extdebug,2,  "DIT EVENTJAAR: ER IS PRECIES ONE DEELNAME RECORD GEVONDEN [DEEL]",
                                "$eventjaar_one_deel_part_id ($eventjaar_one_deel_kampkort $eventjaar_refyear)");
    }
    if ($eventjaar_all_leid_count  == 1) { ### 1 ONE LEID
        wachthond($extdebug,2,  "DIT EVENTJAAR: ER IS PRECIES ONE DEELNAME RECORD GEVONDEN [LEID]",
                                "$eventjaar_one_leid_part_id ($eventjaar_one_leid_kampkort $eventjaar_refyear)");
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 1.6 b BEPAAL WELK EVENT WORDT GEBRUIKT VOOR WAARDEN 'DITJAAR'", $ditjaar_refyear);
    wachthond($extdebug,2, "########################################################################");

    // Initialiseer primaire registratievariabelen — worden hieronder conditioneel overschreven.
    // Zonder initialisatie resulteren latere checks in undefined-variable warnings als geen
    // van de condities matcht (0 registraties, of >1 zonder duidelijke winnaar).
    $ditjaar_prim_partid        = NULL;
    $ditjaar_prim_eventid       = NULL;
    $ditjaar_prim_status_id     = NULL;
    $ditjaar_prim_event_type_id = NULL;

    ##########################################################################################
    // INDIEN PRECIES 1 REGISTRATIE VOOR DIT JAAR: GEBRUIK DIE VOOR BIJWERKEN EVENTINFO DITJAAR
    ##########################################################################################

    if ($ditjaar_all_count == 1 AND $ditjaar_one_part_id > 0) {
        $ditjaar_prim_partid        = $ditjaar_one_part_id;
        $ditjaar_prim_eventid       = $ditjaar_one_event_id;
        $ditjaar_prim_event_type_id = $ditjaar_one_event_type_id;
        $ditjaar_prim_status_id     = $ditjaar_one_status_id;
        wachthond($extdebug,1,  "DITJAAR ONE - ER IS DITJAAR MAAR 1 REGISTRATIE",     
                  "$ditjaar_one_kampkort $ditjaar_refyear [eventid: $ditjaar_one_event_id / partid: $ditjaar_one_part_id]");
    }

    ###############################################################################################
    // INDIEN >1 REGISTRATIES DIT JAAR MAAR PRECIES 1 POSITIEVE: GEBRUIK DIE VOOR EVENTINFO DITJAAR
    ###############################################################################################

    if ($ditjaar_pos_count == 1 AND $ditjaar_all_count > 1 AND $ditjaar_pos_part_id > 0) {
        $ditjaar_prim_partid        = $ditjaar_pos_part_id;
        $ditjaar_prim_eventid       = $ditjaar_pos_event_id;
        $ditjaar_prim_event_type_id = $ditjaar_pos_event_type_id;
        $ditjaar_prim_status_id     = $ditjaar_pos_status_id;
        wachthond($extdebug,1,  "DITJAAR POS - DITJAAR MAAR 1 POSITIEVE REGISTRATIE",
                  "$ditjaar_pos_kampkort $ditjaar_refyear [eventid: $ditjaar_pos_event_id / partid: $ditjaar_pos_part_id]");
    }

    ###############################################################################################
    // INDIEN >1 REGISTRATIES DIT JAAR MAAR PRECIES 1 WACHTLIJST: GEBRUIK DIE VOOR EVENTINFO DITJAAR
    ###############################################################################################

    wachthond($extdebug,4, "ditjaar_all_count",                 $ditjaar_all_count);
    wachthond($extdebug,4, "ditjaar_wait_part_id",              $ditjaar_wait_part_id);
    wachthond($extdebug,4, "ditjaar_wait_count",                $ditjaar_wait_count);

    if ($ditjaar_pos_count == 0 AND $ditjaar_all_count > 1 AND $ditjaar_wait_part_id > 0 AND $ditjaar_wait_count == 1) {
        $ditjaar_prim_partid        = $ditjaar_wait_part_id;
        $ditjaar_prim_eventid       = $ditjaar_wait_event_id;
        $ditjaar_prim_event_type_id = $ditjaar_wait_event_type_id;
        $ditjaar_prim_status_id     = $ditjaar_wait_status_id;
        wachthond($extdebug,1,  "DITJAAR WAIT - DITJAAR MAAR 1 WACHTLIJST REGISTRATIE", 
                  "$ditjaar_wait_kampkort $ditjaar_refyear [eventid: $ditjaar_wait_event_id / partid: $ditjaar_wait_part_id]");
    }

    ###############################################################################################
    // INDIEN >1 REGISTRATIES DIT JAAR MAAR PRECIES 1 PENDING: GEBRUIK DIE VOOR EVENTINFO DITJAAR
    ###############################################################################################

    wachthond($extdebug,4, "ditjaar_all_count",                 $ditjaar_all_count);
    wachthond($extdebug,4, "ditjaar_pen_part_id",               $ditjaar_pen_part_id);
    wachthond($extdebug,4, "ditjaar_pen_count",                 $ditjaar_wait_count);

    if ($ditjaar_pos_count == 0 AND $ditjaar_all_count > 1 AND $ditjaar_pen_part_id > 0 AND $ditjaar_pen_count == 1) {
        $ditjaar_prim_partid        = $ditjaar_pen_part_id;
        $ditjaar_prim_eventid       = $ditjaar_pen_event_id;
        $ditjaar_prim_event_type_id = $ditjaar_pen_event_type_id;
        $ditjaar_prim_status_id     = $ditjaar_pen_status_id;
        wachthond($extdebug,1,  "DITJAAR PEN - DITJAAR MAAR 1 PENDING REGISTRATIE", 
                  "$ditjaar_pen_kampkort $ditjaar_refyear [eventid: $ditjaar_pen_event_id / partid: $ditjaar_pen_part_id]");
        wachthond($extdebug,3,  "DITJAAR PEN",  "$ditjaar_pen_kampkort $ditjaar_refyear [eventid: $ditjaar_pen_event_id / partid: $ditjaar_pen_part_id]");
    }

    if (!$ditjaar_prim_eventid) {
      wachthond($extdebug,2, "########################################################################");
      wachthond($extdebug,1, "### CORE HET IS NIET GELUKT OM EEN PRIMAIR REGISTRATIE TE BEPALEN VOOR $displayname");
      wachthond($extdebug,3, "########################################################################");     
    }

    if ($ditjaar_prim_partid > 0) {

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### CORE 1.6 c GET PART INFO VAN PRIMAIRE EVENT DIT JAAR", "[EID: $ditjaar_prim_eventid / PID: $ditjaar_prim_partid]");
        wachthond($extdebug,3, "########################################################################");

        wachthond($extdebug,2, "pid2part met als input ditjaar_prim_partid", "[PID: $ditjaar_prim_partid]"); 

        $array_partditjaar = base_pid2part($ditjaar_prim_partid);

        wachthond($extdebug,3, "########################################################################");
        wachthond($extdebug,2, "array_partditjaar", $array_partditjaar);
        wachthond($extdebug,3, "########################################################################");

        $displayname                    = $array_partditjaar['displayname']                 ?? NULL;
        $ditjaar_part_contact_id        = $array_partditjaar['contact_id']                  ?? NULL;
        $ditjaar_part_id                = $array_partditjaar['id']                          ?? NULL;
        $ditjaar_part_eventid           = $array_partditjaar['event_id']                    ?? NULL;
        $ditjaar_part_role_id           = $array_partditjaar['role_id']                     ?? NULL;
        $ditjaar_part_status_id         = $array_partditjaar['status_id']                   ?? NULL;
        $ditjaar_part_status_name       = $array_partditjaar['status_name']                 ?? NULL;
        $ditjaar_part_register_date     = $array_partditjaar['register_date']               ?? NULL;
        $ditjaar_part_event_title       = $array_partditjaar['event_title']                 ?? NULL;
        $ditjaar_part_event_type_id     = $array_partditjaar['event_type_id']               ?? NULL;
        $ditjaar_part_event_type_label  = $array_partditjaar['event_type_label']            ?? NULL;

        $ditjaar_part_kampjaar          = $array_partditjaar['part_kampjaar']               ?? NULL;
        $ditjaar_part_kampnaam          = $array_partditjaar['part_kampnaam']               ?? NULL;
        $ditjaar_part_kampkort          = $array_partditjaar['part_kampkort']               ?? NULL;
        $ditjaar_part_kampsoort         = $array_partditjaar['part_kampsoort']              ?? NULL;

        $ditjaar_part_kamptype_id       = $array_partditjaar['part_kamptype_id']            ?? NULL;
        $ditjaar_part_functie           = $array_partditjaar['part_functie']                ?? NULL;
        $ditjaar_part_rol               = $array_partditjaar['part_rol']                    ?? NULL;
        $ditjaar_leid_welkkamp          = $array_partditjaar['part_leid_kamp']              ?? NULL;
        $ditjaar_leid_functie           = $array_partditjaar['part_leid_functie']           ?? NULL;

        // ==============================================================================
        // OVERRIDE DITJAAR: Logica voor Bestuur en Kampstaf
        // ==============================================================================
        if ($ditjaar_part_functie == 'bestuurslid' || $ditjaar_leid_functie == 'bestuurslid' || strtoupper($ditjaar_leid_welkkamp) == 'BESTUUR') {
            $ditjaar_part_kampkort      = 'bst';
            $ditjaar_leid_welkkamp      = 'bst';
            $ditjaar_part_rol           = 'bestuur'; 
            $ditjaar_part_functie       = 'bestuurslid';
            
            wachthond($extdebug, 2, "OVERRIDE UITGEVOERD (DITJAAR)", "Kampkort overschreven naar 'bst'");

        } elseif ($ditjaar_part_functie == 'kampstaf' || $ditjaar_leid_functie == 'kampstaf' || strtoupper($ditjaar_leid_welkkamp) == 'KAMPSTAF') {
            $ditjaar_part_kampkort      = 'kst';
            $ditjaar_leid_welkkamp      = 'kst';
            $ditjaar_part_rol           = 'kampstaf'; 
            $ditjaar_part_functie       = 'kampstaf';
            
            wachthond($extdebug, 2, "OVERRIDE UITGEVOERD (DITJAAR)", "Kampkort overschreven naar 'kst'");
        }

        $ditjaar_part_vakantieregio     = $array_partditjaar['part_vakantieregio']          ?? NULL;

        $ditjaar_part_1stdeel           = $array_partditjaar['part_1stdeel']                ?? NULL;
        $ditjaar_part_1stleid           = $array_partditjaar['part_1stleid']                ?? NULL;

        $ditjaar_part_nawgecheckt       = $array_partditjaar['part_nawgecheckt']            ?? NULL;
        $ditjaar_part_biogecheckt       = $array_partditjaar['part_biogecheckt']            ?? NULL;

        $ditjaar_part_groepklas         = $array_partditjaar['part_groepklas']              ?? NULL;
        $ditjaar_part_voorkeur          = $array_partditjaar['part_voorkeur']               ?? NULL;
        $ditjaar_part_groepsletter      = $array_partditjaar['part_groepsletter']           ?? NULL;
        $ditjaar_part_groepskleur       = $array_partditjaar['part_groepskleur']            ?? NULL;
        $ditjaar_part_groepsnaam        = $array_partditjaar['part_groepsnaam']             ?? NULL;
        $ditjaar_part_slaapzaal         = $array_partditjaar['part_slaapzaal']              ?? NULL;

        $ditjaar_wachtlijst_erop        = $array_partditjaar['wachtlijst_erop']             ?? NULL;
        $ditjaar_wachtlijst_eraf        = $array_partditjaar['wachtlijst_eraf']             ?? NULL;
        $ditjaar_criteriacheck_start    = $array_partditjaar['criteriacheck_start']         ?? NULL;
        $ditjaar_criteriacheck_einde    = $array_partditjaar['criteriacheck_einde']         ?? NULL;
        $ditjaar_criteria_indicatie     = $array_partditjaar['criteria_indicatie']          ?? NULL;
        $ditjaar_criteria_oordeel       = $array_partditjaar['criteria_oordeel']            ?? NULL;

        $ditjaar_part_notificatie_deel  = $array_partditjaar['part_notificatie_deel']       ?? NULL;
        $ditjaar_part_notificatie_leid  = $array_partditjaar['part_notificatie_leid']       ?? NULL;
        $ditjaar_part_notificatie_kamp  = $array_partditjaar['part_notificatie_kamp']       ?? NULL;
        $ditjaar_part_notificatie_staf  = $array_partditjaar['part_notificatie_staf']       ?? NULL;
        $ditjaar_part_notificatie_priv  = $array_partditjaar['part_notificatie_priv']       ?? NULL;

        $ditjaar_part_kampgeld_contribid= $array_partditjaar['part_kampgeld_contribid']     ?? NULL;
        $ditjaar_part_kampgeld_regeling = $array_partditjaar['part_kampgeld_regeling']      ?? NULL;
        $ditjaar_part_kampgeld_fietshuur= $array_partditjaar['part_kampgeld_fietshuur']     ?? NULL;
//      $ditjaar_event_fietsevent       = $array_partditjaar['event_fietsevent']            ?? NULL;

        $ditjaar_part_evaluatie_datum   = $array_partditjaar['part_evaluatie_datum']        ?? NULL;

        if (in_array($ditjaar_part_event_type_id, $eventtypesleidall)) {
            $ditjaar_part_vogverzocht   = $array_partditjaar['part_vogverzoek']             ?? NULL;
            $ditjaar_part_vogingediend  = $array_partditjaar['part_vogaanvraag']            ?? NULL;
            $ditjaar_part_vogontvangst  = $array_partditjaar['part_vogontvangst']           ?? NULL;
            $ditjaar_part_vogdatum      = $array_partditjaar['part_vogdatum']               ?? NULL;
            $ditjaar_part_vogkenmerk    = $array_partditjaar['part_vogkenmerk']             ?? NULL;
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### CORE 1.6 d GET EVENT INFO VAN PRIMAIRE EVENT DIT JAAR", "[EID: $ditjaar_prim_eventid / PID: $ditjaar_prim_partid]");
        wachthond($extdebug,3, "########################################################################");

        $array_eventinfo_ditjaar = base_eid2event($ditjaar_prim_eventid, $ditjaar_prim_partid);

        wachthond($extdebug,4, "########################################################################");
        wachthond($extdebug,2, "array_eventinfo",                                $array_eventinfo_ditjaar);
        wachthond($extdebug,4, "########################################################################");

        $ditjaar_event_id               = $array_eventinfo_ditjaar['eventkamp_event_id']            ?? NULL;
        $ditjaar_event_type_id          = $array_eventinfo_ditjaar['eventkamp_event_type_id']       ?? NULL;
        $ditjaar_event_type_id_label    = $array_eventinfo_ditjaar['eventkamp_event_type_id_label'] ?? NULL;

        $ditjaar_event_kamptype_naam    = $array_eventinfo_ditjaar['eventkamp_kamptype_naam']       ?? NULL;
        $ditjaar_event_kamptype_label   = $array_eventinfo_ditjaar['eventkamp_kamptype_label']      ?? NULL;
        $ditjaar_event_kamptype_id      = $array_eventinfo_ditjaar['eventkamp_kamptype_id']         ?? NULL;
        $ditjaar_event_kampsoort        = $array_eventinfo_ditjaar['eventkamp_kampsoort']           ?? NULL;

        $ditjaar_event_kampnaam         = $array_eventinfo_ditjaar['eventkamp_kampnaam']            ?? NULL;
        $ditjaar_event_kampkort         = $array_eventinfo_ditjaar['eventkamp_kampkort']            ?? NULL;

        // ==============================================================================
        // OVERRIDE: Forceer Event Data voor Bestuur en Kampstaf (tbv DB Save in Sectie 8)
        // ==============================================================================
        if ($ditjaar_part_rol == 'bestuur') {
            $ditjaar_event_kampkort = 'bst';
            $ditjaar_event_kampnaam = 'Bestuur';
            $ditjaar_event_pleklang = 'N.v.t.';
            $ditjaar_event_stadlang = 'N.v.t.';
        } elseif ($ditjaar_part_rol == 'kampstaf') {
            $ditjaar_event_kampkort = 'kst';
            $ditjaar_event_kampnaam = 'Kampstaf';
            $ditjaar_event_pleklang = 'N.v.t.';
            $ditjaar_event_stadlang = 'N.v.t.';
        }

        $ditjaar_event_start            = $array_eventinfo_ditjaar['eventkamp_event_start']         ?? NULL;
        $ditjaar_event_einde            = $array_eventinfo_ditjaar['eventkamp_event_einde']         ?? NULL;
        $ditjaar_event_weeknr           = $array_eventinfo_ditjaar['eventkamp_event_weeknr']        ?? NULL;

        $ditjaar_fiscalyear_start       = $array_eventinfo_ditjaar['eventkamp_fiscalyear_start']    ?? NULL;
        $ditjaar_fiscalyear_einde       = $array_eventinfo_ditjaar['eventkamp_fiscalyear_einde']    ?? NULL;      
        $ditjaar_event_kampjaar         = $array_eventinfo_ditjaar['eventkamp_kampjaar']            ?? NULL;
        $ditjaar_event_kampjaarkort     = $array_eventinfo_ditjaar['eventkamp_kampjaarkort']        ?? NULL;

        $ditjaar_event_plek             = $array_eventinfo_ditjaar['eventkamp_plek']                ?? NULL;
        $ditjaar_event_stad             = $array_eventinfo_ditjaar['eventkamp_stad']                ?? NULL;
        $ditjaar_event_pleklang         = $array_eventinfo_ditjaar['eventkamp_pleklang']            ?? NULL;
        $ditjaar_event_stadlang         = $array_eventinfo_ditjaar['eventkamp_stadlang']            ?? NULL;

        $ditjaar_event_fietsevent       = $array_eventinfo_ditjaar['eventkamp_fietsevent']          ?? NULL;

        $ditjaar_brengen_van            = $array_eventinfo_ditjaar['eventkamp_brengen_van']         ?? NULL;
        $ditjaar_brengen_tot            = $array_eventinfo_ditjaar['eventkamp_brengen_tot']         ?? NULL;
        $ditjaar_pres_van               = $array_eventinfo_ditjaar['eventkamp_pres_van']            ?? NULL;
        $ditjaar_pres_tot               = $array_eventinfo_ditjaar['eventkamp_pres_tot']            ?? NULL;
        $ditjaar_halen_van              = $array_eventinfo_ditjaar['eventkamp_halen_van']           ?? NULL;
        $ditjaar_halen_tot              = $array_eventinfo_ditjaar['eventkamp_halen_tot']           ?? NULL;

        $ditjaar_thema_naam             = $array_eventinfo_ditjaar['eventkamp_thema_naam']          ?? NULL;
        $ditjaar_thema_info             = $array_eventinfo_ditjaar['eventkamp_thema_info']          ?? NULL;
        $ditjaar_goeddoel_naam          = $array_eventinfo_ditjaar['eventkamp_goeddoel_naam']       ?? NULL;
        $ditjaar_goeddoel_info          = $array_eventinfo_ditjaar['eventkamp_goeddoel_info']       ?? NULL;
        $ditjaar_goeddoel_link          = $array_eventinfo_ditjaar['eventkamp_goeddoel_link']       ?? NULL;

        $ditjaar_welkomvideo            = $array_eventinfo_ditjaar['eventkamp_welkomvideo']         ?? NULL;
        $ditjaar_slotvideo              = $array_eventinfo_ditjaar['eventkamp_slotvideo']           ?? NULL;
        $ditjaar_extrabagage            = $array_eventinfo_ditjaar['eventkamp_extrabagage']         ?? NULL;
        $ditjaar_playlist               = $array_eventinfo_ditjaar['eventkamp_playlist']            ?? NULL;
        $ditjaar_doc_link               = $array_eventinfo_ditjaar['eventkamp_doc_link']            ?? NULL;
        $ditjaar_doc_info               = $array_eventinfo_ditjaar['eventkamp_doc_info']            ?? NULL;
        $ditjaar_foto_vraag             = $array_eventinfo_ditjaar['eventkamp_foto_vraag']          ?? NULL;
        $ditjaar_foto_album             = $array_eventinfo_ditjaar['eventkamp_foto_album']          ?? NULL;

        $ditjaar_hldn1_id               = $array_eventinfo_ditjaar['event_hldn1_id']                ?? NULL;
        $ditjaar_hldn2_id               = $array_eventinfo_ditjaar['event_hldn2_id']                ?? NULL;
        $ditjaar_hldn3_id               = $array_eventinfo_ditjaar['event_hldn3_id']                ?? NULL;

        $ditjaar_kern1_id               = $array_eventinfo_ditjaar['event_kern1_id']                ?? NULL;
        $ditjaar_kern2_id               = $array_eventinfo_ditjaar['event_kern2_id']                ?? NULL;
        $ditjaar_kern3_id               = $array_eventinfo_ditjaar['event_kern3_id']                ?? NULL;

        $ditjaar_keuken0_id             = $array_eventinfo_ditjaar['event_keuken0_id']              ?? NULL;
        $ditjaar_keuken1_id             = $array_eventinfo_ditjaar['event_keuken1_id']              ?? NULL;
        $ditjaar_keuken2_id             = $array_eventinfo_ditjaar['event_keuken2_id']              ?? NULL;
        $ditjaar_keuken3_id             = $array_eventinfo_ditjaar['event_keuken3_id']              ?? NULL;

        $ditjaar_gedrag0_id             = $array_eventinfo_ditjaar['event_gedrag0_id']              ?? NULL;
        $ditjaar_gedrag1_id             = $array_eventinfo_ditjaar['event_gedrag1_id']              ?? NULL;
        $ditjaar_gedrag2_id             = $array_eventinfo_ditjaar['event_gedrag2_id']              ?? NULL;

        $ditjaar_boekje0_id             = $array_eventinfo_ditjaar['event_boekje0_id']              ?? NULL;
        $ditjaar_boekje1_id             = $array_eventinfo_ditjaar['event_boekje1_id']              ?? NULL;
        $ditjaar_boekje2_id             = $array_eventinfo_ditjaar['event_boekje2_id']              ?? NULL;

        $ditjaar_ehbo0_id               = $array_eventinfo_ditjaar['event_ehbo0_id']                ?? NULL;
        $ditjaar_ehbo1_id               = $array_eventinfo_ditjaar['event_ehbo1_id']                ?? NULL;
        $ditjaar_ehbo2_id               = $array_eventinfo_ditjaar['event_ehbo2_id']                ?? NULL;
        $ditjaar_ehbo3_id               = $array_eventinfo_ditjaar['event_ehbo3_id']                ?? NULL;

        $ditjaar_rollen_array = array(
            'event_hldn1_id'    => $ditjaar_hldn1_id,
            'event_hldn2_id'    => $ditjaar_hldn2_id,
            'event_hldn3_id'    => $ditjaar_hldn3_id,

            'event_kern1_id'    => $ditjaar_kern1_id,
            'event_kern2_id'    => $ditjaar_kern2_id,
            'event_kern3_id'    => $ditjaar_kern3_id,

            'event_keuken0_id'  => $ditjaar_keuken0_id,
            'event_keuken1_id'  => $ditjaar_keuken1_id,
            'event_keuken2_id'  => $ditjaar_keuken2_id,
            'event_keuken3_id'  => $ditjaar_keuken3_id,

            'event_gedrag0_id'  => $ditjaar_gedrag0_id,
            'event_gedrag1_id'  => $ditjaar_gedrag1_id,
            'event_gedrag2_id'  => $ditjaar_gedrag2_id,

            'event_boekje0_id'  => $ditjaar_boekje0_id,
            'event_boekje1_id'  => $ditjaar_boekje1_id,
            'event_boekje2_id'  => $ditjaar_boekje2_id,

            'event_ehbo0_id'    => $ditjaar_ehbo0_id,
            'event_ehbo1_id'    => $ditjaar_ehbo1_id,
            'event_ehbo2_id'    => $ditjaar_ehbo2_id,
            'event_ehbo3_id'    => $ditjaar_ehbo3_id,
        );

        wachthond($extdebug,3, 'ditjaar_rollen_array', $ditjaar_rollen_array);

        // Gave-logica verplaatst naar nl.onvergetelijk.stgave (stgave_civicrm_configure).
        // Dit omvat: relatie type 20 ophalen, telefoon/email sync → locatietype Gave (26),
        // line items 175/-55/-120, en regeling 'ja_stgave'.
        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### CORE 4.9 STGAVE CONFIGURE",                     "[PID: $ditjaar_prim_partid]");
        wachthond($extdebug,2, "########################################################################");

        watchdog('civicrm_timing', base_microtimer("START configure REL/GAVE (stgave ext)"), NULL, WATCHDOG_DEBUG);
        if (function_exists('stgave_civicrm_configure')) {
            $result_stgave = stgave_civicrm_configure($contact_id, $array_partditjaar);
            wachthond($extdebug, 3, 'result_stgave',                 $result_stgave);
        } else {
            wachthond($extdebug, 1, "SKIP stgave_civicrm_configure: extensie niet actief", "[CID: $contact_id]");
        }
        watchdog('civicrm_timing', base_microtimer("EINDE configure REL/GAVE (stgave ext)"), NULL, WATCHDOG_DEBUG);

    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.9b STGAVE CONFIGURE ALLE JAREN",             "[CID: $contact_id]");
    wachthond($extdebug,2, "########################################################################");

    watchdog('civicrm_timing', base_microtimer("START stgave alle jaren"), NULL, WATCHDOG_DEBUG);
    if (function_exists('stgave_civicrm_configure')) {
        // Trigger stgave ALTIJD, niet alleen voor ditjaar. De detectielogica in stgave
        // bepaalt zelf of dit een St.Gave contact is (rol 16, regeling ja_stgave, of line items).
        // Dit zorgt dat ook historische St.Gave deelnemers kunnen worden hersteld.
        $part_array_alle = base_pid2part($entityID) ?: [];
        $result_stgave_alle = stgave_civicrm_configure($contact_id, $part_array_alle);
        wachthond($extdebug, 3, 'result_stgave_alle',               $result_stgave_alle);
    } else {
        wachthond($extdebug, 1, "SKIP stgave_civicrm_configure alle jaren: extensie niet actief", "[CID: $contact_id]");
    }
    watchdog('civicrm_timing', base_microtimer("EINDE stgave alle jaren"), NULL, WATCHDOG_DEBUG);

    watchdog('civicrm_timing', base_microtimer("EINDE 1.X get variables"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CORE 1.9 VROEGE PERSIST part_kampkort (deadlock-veiligheid)", "[$eventkamp_kampkort]");
    wachthond($extdebug, 2, "########################################################################");

    /*
     * SAMENVATTING SECTIE 1.9 — VROEGE PERSIST part_kampkort (deadlock-veiligheid)
     * ----------------------------------------------------------------------------------------
     * FUNCTIONEEL (het waarom):
     *   part_kampkort (het kampcodeveld op de deelnemer, bv. 'jk1') is de spil van de
     *   criteria-check: zonder kampkort matcht geen enkele leeftijds-/schoolband en wordt een
     *   deelnemer onterecht als 'afwijkend' beoordeeld -> wachtlijst + een spannende oudermail.
     *   Dit veld wordt normaal vanuit het event op de deelnemer gesynct, maar dat gebeurt pas
     *   verderop in CORE 8.2 (params bouwen) / CORE 99.x (de daadwerkelijke API-write) — dus NÁ
     *   de ACL-sectie (4.3) en de email-sectie (4.4).
     *
     * TECHNISCH (het probleem dat we hier afvangen):
     *   Bij een online inschrijving vuurt de browser een burst van overlappende requests, die
     *   elk (delen van) deze trage hook-stack draaien (~12-19s). Een nieuwe inschrijving voegt
     *   het contact toe aan z'n kamp/rol-groepen; CiviCRM TRUNCATE't daarop civicrm_acl_contact_cache.
     *   Leest een gelijktijdige request die cache net dan (ACL-permissiecheck in 4.3/4.4), dan
     *   volgt een MySQL-deadlock (1213) / "table definition has changed" (1412) -> fatale
     *   DBQueryException -> de request breekt af VÓÓR CORE 8.2, en part_kampkort blijft leeg.
     *   (Zie casus Rowan Buijl 24-jun-2026; geheugen: acl_cache_deadlock_registratie /
     *   criteria_kampkort_fallback.)
     *
     * OPLOSSING (waarom hier, waarom zo):
     *   We persisten het kampkort hier alvast, ruim vóór de abort-gevoelige secties, zodat het
     *   een latere abort overleeft. Bewust via DIRECTE SQL i.p.v. base_api_wrapper():
     *     - GEEN hooks -> geen extra participant-write-cascade, geen ACL-cache-reset, geen
     *       re-entrancy in deze hook (de anti-recursie-guard zit in core_civicrm_post).
     *     - Eén kleine single-row upsert op civicrm_value_part_118 -> verwaarloosbare lock,
     *       raakt de ACL-cache niet, draagt dus niet bij aan de deadlock.
     *   Dit is een vangnet (belt-and-suspenders) bovenop de kampkort-fallback in partstatus;
     *   het is volledig IDEMPOTENT: CORE 8.2/99.x schrijft exact dezelfde waarde later nog eens.
     *
     * GUARD (gelijk aan CORE 8.2):
     *   $extdjpart                       -> dit event levert participant-waarden (gezet in 0.X)
     *   in_array($groupID, $profilepart) -> alleen op een PART-profiel (deel/leid), niet op contact-only
     *   $ditevent_part_id > 0            -> er is een concrete deelnemer om op te schrijven
     *   !empty($eventkamp_kampkort)      -> alleen schrijven als het event écht een kampkort heeft
     *                                       (anders zouden we een geldige waarde met leeg overschrijven)
     *
     * VELD-IDS (hardcoded, conform OZK-conventie):
     *   civicrm_value_part_118 = custom group PART; kolom part_kampkort_950 = custom field
     *   PART.PART_kampkort (id 950). entity_id is de unieke sleutel -> ON DUPLICATE KEY UPDATE
     *   maakt de rij aan als die nog niet bestaat, of werkt enkel het kampkort bij als die er al is.
     */
    if ($extdjpart == 1 && in_array($groupID, $profilepart) && $ditevent_part_id > 0 && !empty($eventkamp_kampkort)) {
        try {
            // %1/%2 zijn geparametriseerd (geen SQL-injectie); %2 wordt 2x gebruikt (VALUES + UPDATE).
            CRM_Core_DAO::executeQuery(
                "INSERT INTO civicrm_value_part_118 (entity_id, part_kampkort_950) VALUES (%1, %2)
                 ON DUPLICATE KEY UPDATE part_kampkort_950 = %2",
                [1 => [$ditevent_part_id, 'Integer'], 2 => [$eventkamp_kampkort, 'String']]
            );
            wachthond($extdebug, 1, "Vroege kampkort-persist OK", "[PID $ditevent_part_id = $eventkamp_kampkort]");
        } catch (\Throwable $e) {
            // Niet fataal: dit is enkel een vangnet. CORE 8.2/99.x schrijft het kampkort later nog
            // een keer, en partstatus_criteria valt sowieso terug op het event-kampkort + markeert
            // incomplete data. We loggen en gaan door, zodat dit vangnet de registratie nooit blokkeert.
            wachthond($extdebug, 1, "Vroege kampkort-persist faalde (genegeerd)", $e->getMessage());
        }
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CORE 2.X PARTSTATUS LEEFTIJD/CRITERIA/WACHTLIJST",   "[$displayname]");
    wachthond($extdebug, 2, "########################################################################");

    // --------------------------------------------------------------------------------------
    // 1. PARTSTATUS ENGINE UITVOEREN VOOR DIT EVENT
    // --------------------------------------------------------------------------------------

    // Initialiseer even bovenaan voor de veiligheid
    $array_criteria_ditevent     = NULL;
    $array_status_ditevent       = NULL;
    $leeftijd_ditevent_decimalen = NULL;

    if (!empty($ditevent_part_id)) {
        // Engine aanroepen met context 'ditevent'
        $ctx_ditevent = partstatus_configure($ditevent_part_id, $array_partditevent, NULL, 'ditevent');

        // Veilige mapping: deze variabelen bestaan nu alleen als er een berekende context is
        $array_criteria_ditevent     = $ctx_ditevent['criteria'] ?? NULL;
        $array_status_ditevent       = $ctx_ditevent; 
        $leeftijd_ditevent_decimalen = $ctx_ditevent['leeftijd']['event']['leeftijd_decimalen'] ?? NULL;
        $leeftijd_ditevent_rondjaren = $ctx_ditevent['leeftijd']['event']['leeftijd_rondjaren'] ?? NULL;
        $leeftijd_ditevent_rondmaand = $ctx_ditevent['leeftijd']['event']['leeftijd_rondmaand'] ?? NULL;
    }

    // --------------------------------------------------------------------------------------
    // 2. PARTSTATUS ENGINE UITVOEREN VOOR DIT JAAR (Indien dit een andere registratie is)
    // --------------------------------------------------------------------------------------

    if (!empty($ditjaar_prim_partid)) {
        if ($ditjaar_prim_partid == $ditevent_part_id) {
            $ctx_ditjaar = $ctx_ditevent;
        } else {
            // Engine aanroepen met context 'ditjaar'
            $ctx_ditjaar = partstatus_configure($ditjaar_prim_partid, $array_partditjaar, NULL, 'ditjaar');
        }

        // Veilige mapping binnen de IF
        $array_criteria_ditjaar      = $ctx_ditjaar['criteria'] ?? NULL;
        $array_status_ditjaar        = $ctx_ditjaar;
    }

    // --------------------------------------------------------------------------------------
    // 3. VARIABELEN VOOR SECTIE 8 (Gevuld vanuit de 'Super Array')
    // --------------------------------------------------------------------------------------
    // Vandaag ijkpunt
    $leeftijd_vantoday_decimalen = $ctx_ditjaar['leeftijd']['today']['leeftijd_decimalen']  ?? NULL;
    $leeftijd_vantoday_rondjaren = $ctx_ditjaar['leeftijd']['today']['leeftijd_rondjaren']  ?? NULL;

    // Next kamp ijkpunt
    $leeftijd_nextkamp_decimalen = $ctx_ditjaar['leeftijd']['next']['leeftijd_decimalen']   ?? NULL;
    $leeftijd_nextkamp_rondjaren = $ctx_ditjaar['leeftijd']['next']['leeftijd_rondjaren']   ?? NULL;
    $leeftijd_nextkamp_rondmaand = $ctx_ditjaar['leeftijd']['next']['leeftijd_rondmaand']   ?? NULL;

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### CORE 2.7 BEPAAL DE DEELNAMESTATUS DIT JAAR EN DIT EVENT");
    wachthond($extdebug,3, "########################################################################");

    wachthond($extdebug,4, "array_allpart_eventjaar", $array_allpart_eventjaar);
    wachthond($extdebug,3, "array_partditevent",      $array_partditevent);
    wachthond($extdebug,3, "array_status_ditevent",   $array_status_ditevent);
    wachthond($extdebug,1, 'array_criteria_ditevent', $array_criteria_ditevent);

    wachthond($extdebug,1, base_microtimer("Start raadplegen mee_configure ditevent"));
    // 1. BEREKEN STATUS VOOR HET EVENT
    $ditevent_array     = mee_civicrm_configure($contact_id,                $array_allpart_eventjaar,
                                                $array_partditevent,        $array_status_ditevent,     $array_criteria_ditevent);  // jaar event

    wachthond($extdebug,1, base_microtimer("Einde raadplegen mee_configure ditevent"));

    wachthond($extdebug,4, "########################################################################");    
    wachthond($extdebug,1, "A RECEIVE ditevent_array",                        $ditevent_array);
    wachthond($extdebug,2, "########################################################################");    

    // Hier pakken we de 'ditevent' sleutels
    $diteventdeelyes        = $ditevent_array['diteventdeelyes'];
    $diteventdeelnot        = $ditevent_array['diteventdeelnot'];
    $diteventdeelmss        = $ditevent_array['diteventdeelmss'];
    $diteventdeelstf        = $ditevent_array['diteventdeelstf'];
    $diteventdeeltst        = $ditevent_array['diteventdeeltst'];
    $diteventdeeltxt        = $ditevent_array['diteventdeeltxt'];

    $diteventleidyes        = $ditevent_array['diteventleidyes'];
    $diteventleidnot        = $ditevent_array['diteventleidnot'];
    $diteventleidmss        = $ditevent_array['diteventleidmss'];
    $diteventleidstf        = $ditevent_array['diteventleidstf'];
    $diteventleidtst        = $ditevent_array['diteventleidtst'];
    $diteventleidtxt        = $ditevent_array['diteventleidtxt'];

    wachthond($extdebug,4, 'array_allpart_ditjaar',     $array_allpart_ditjaar);
    wachthond($extdebug,3, 'array_partditjaar',         $array_partditjaar);
    wachthond($extdebug,3, 'array_status_ditjaar',      $array_status_ditjaar);
    wachthond($extdebug,3, 'array_criteria_ditjaar',    $array_criteria_ditjaar);

    wachthond($extdebug,1, base_microtimer("Start raadplegen mee_configure ditjaar"));
    // 2. BEREKEN STATUS VOOR HET HUIDIGE JAAR (Los van het event)
    $ditjaar_array      = mee_civicrm_configure($contact_id,             $array_allpart_ditjaar, 
                                                $array_partditjaar,      $array_status_ditjaar,          $array_criteria_ditjaar);  // huidig jaar
    wachthond($extdebug,1, base_microtimer("Einde raadplegen mee_configure ditjaar"));

    wachthond($extdebug,4, "########################################################################");    
    wachthond($extdebug,1, "B RECEIVE ditjaar_array",                                  $ditjaar_array);
    wachthond($extdebug,2, "########################################################################");    

    // AANPASSING: We lezen nu de 'ditjaar' sleutels uit de array.
    // Omdat de mee-module nu een merged array teruggeeft, bevat $ditjaar_array ook de correcte 'ditjaar...' keys.
    
    $ditjaardeelyes         = $ditjaar_array['ditjaardeelyes'];
    $ditjaardeelnot         = $ditjaar_array['ditjaardeelnot'];
    $ditjaardeelmss         = $ditjaar_array['ditjaardeelmss'];
    $ditjaardeelstf         = $ditjaar_array['ditjaardeelstf'];
    $ditjaardeeltst         = $ditjaar_array['ditjaardeeltst'];
    $ditjaardeeltxt         = $ditjaar_array['ditjaardeeltxt'];

    $ditjaarleidyes         = $ditjaar_array['ditjaarleidyes'];
    $ditjaarleidnot         = $ditjaar_array['ditjaarleidnot'];
    $ditjaarleidmss         = $ditjaar_array['ditjaarleidmss'];
    $ditjaarleidstf         = $ditjaar_array['ditjaarleidstf'];
    $ditjaarleidtst         = $ditjaar_array['ditjaarleidtst'];
    $ditjaarleidtxt         = $ditjaar_array['ditjaarleidtxt'];

    // Fallback: Als er helemaal geen data is voor het jaar (count == 0), forceer naar NOT.
    // (Dit vangt situaties af waar de mee-module misschien lege waardes teruggaf voor een leeg jaar).
    if (empty($ditjaar_all_count) || $ditjaar_all_count == 0) {
        $ditjaardeelnot     = 1;
        $ditjaarleidnot     = 1;
    }

    wachthond($extdebug,3, 'diteventdeelyes',   $diteventdeelyes);
    wachthond($extdebug,3, 'diteventdeelmss',   $diteventdeelmss);
    wachthond($extdebug,3, 'diteventleidyes',   $diteventleidyes);
    wachthond($extdebug,3, 'diteventleidmss',   $diteventleidmss);

    wachthond($extdebug,2, "########################################################################");    

    wachthond($extdebug,3, 'ditjaardeelyes',    $ditjaardeelyes);
    wachthond($extdebug,3, 'ditjaardeelmss',    $ditjaardeelmss);
    wachthond($extdebug,3, 'ditjaarleidyes',    $ditjaarleidyes);
    wachthond($extdebug,3, 'ditjaarleidmss',    $ditjaarleidmss);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### 2.8 TOON DEFINITIEVE DEELNAMESTATUS VAN DIT JAAR EN DIT EVENT");
    wachthond($extdebug,2, "########################################################################");

    if ($ditjaar_all_count >= 1) {
        wachthond($extdebug,1,  "DITJAAR      $ditjaar_part_kampjaar GAAT $displayname $ditjaardeeltxt MEE ALS DEEL",
                  "[EID $ditjaar_pos_deel_event_id\tPID $ditjaar_pos_deel_part_id\t$ditjaar_pos_deel_kampkort]");
        wachthond($extdebug,1,  "DITJAAR      $ditjaar_part_kampjaar GAAT $displayname $ditjaarleidtxt MEE ALS LEID",
                  "[EID $ditjaar_pos_leid_event_id\tPID $ditjaar_pos_leid_part_id\t$ditjaar_pos_leid_kampkort]");
        wachthond($extdebug,2, "########################################################################");
    } else {
        wachthond($extdebug,1,  "DITJAAR      $ditjaar_part_kampjaar GAAT $displayname NIET MEE OP KAMP");
    }
    if ($ditevent_part_id >= 1) {
        wachthond($extdebug,1,  "DITEVENT     $ditevent_kampjaar GAAT $displayname $diteventdeeltxt MEE ALS DEEL",
                  "[EID $ditevent_part_eventid\tPID $ditevent_part_id\t$ditevent_part_kampkort]");
        wachthond($extdebug,1,  "DITEVENT     $ditevent_kampjaar GAAT $displayname $diteventleidtxt MEE ALS LEID",
                  "[EID $ditevent_part_eventid\tPID $ditevent_part_id\t$ditevent_part_kampkort]");
        wachthond($extdebug,2, "########################################################################");
    } else {
        wachthond($extdebug,1,  "DITEVENT     [ER WORDT GEEN EVENT BEWERKT]");
    }

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,1, "### CORE 2.X EINDE BEPAAL BASISINFO VOOR DIT CONTACT / DEZE PARTICIPANT", "$displayname");
    wachthond($extdebug,3, "########################################################################");

    watchdog('civicrm_timing', base_microtimer("EINDE 2.X bepaal basisinfo"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE 3.X START CORRECT CV",           "[groupID: $groupID] [op: $op]");
    wachthond($extdebug,1, "########################################################################");

    watchdog('civicrm_timing', base_microtimer("START correct CV"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,3, 'contact_id',            $contact_id);
    wachthond($extdebug,3, 'array_contditjaar',     $array_contditjaar);
    wachthond($extdebug,4, 'ditjaar_array',         $ditjaar_array);

    if ($contact_id AND is_array($array_contditjaar)) {

        wachthond($extdebug,3,      'CORRECT CV',   "EXECUTE");

        watchdog('civicrm_timing', base_microtimer("START bepaal CV"), NULL, WATCHDOG_DEBUG);
        $array_cv = cv_civicrm_configure($contact_id, $array_contditjaar, $ditjaar_array);
        watchdog('civicrm_timing', base_microtimer("EINDE bepaal CV"), NULL, WATCHDOG_DEBUG);
        wachthond($extdebug,3,  'RECEIVE array_cv', $array_cv);

    } else {
        
        wachthond($extdebug,3,      'CORRECT CV',   "SKIP (no valid input)");
    }

    if (is_array($array_cv)) {

        $keren_deel     = $array_cv['keren_deel']       ?? NULL;
        $keren_leid     = $array_cv['keren_leid']       ?? NULL;
        $keren_top      = $array_cv['keren_top']        ?? NULL;
        $totaal_mee     = $array_cv['totaal_mee']       ?? NULL;

        $eerste_deel    = $array_cv['eerste_deel']      ?? NULL;
        $eerste_leid    = $array_cv['eerste_leid']      ?? NULL;
        $eerste_top     = $array_cv['eerste_top']       ?? NULL;
        $eerste_keer    = $array_cv['eerste_keer']      ?? NULL;

        $laatste_deel   = $array_cv['laatste_deel']     ?? NULL;
        $laatste_leid   = $array_cv['laatste_leid']     ?? NULL;
        $laatste_top    = $array_cv['laatste_top']      ?? NULL;
        $laatste_keer   = $array_cv['laatste_keer']     ?? NULL;

        $cv_deel        = $array_cv['cv_deel']          ?? NULL;
        $cv_leid        = $array_cv['cv_leid']          ?? NULL;

        $cv_deel_text   = $array_cv['cv_deel_text']     ?? NULL;
        $cv_leid_text   = $array_cv['cv_leid_text']     ?? NULL;

        $evtcv_deel     = $array_cv['evtcv_deel']       ?? NULL;
        $evtcv_leid     = $array_cv['evtcv_leid']       ?? NULL;

        $evtcv_deel_nr  = $array_cv['evtcv_deel_nr']    ?? NULL;
        $evtcv_leid_nr  = $array_cv['evtcv_leid_nr']    ?? NULL;

        $evtcv_deel_dif = $array_cv['evtcv_deel_dif']   ?? NULL;
        $evtcv_leid_dif = $array_cv['evtcv_leid_dif']   ?? NULL;
    }

    watchdog('civicrm_timing', base_microtimer("EINDE correct CV"), NULL, WATCHDOG_DEBUG);

    ##########################################################################################
    # 4.X START CORRECT MISCELANEOUS VALUES
    ##########################################################################################

    if (in_array($groupID, $profilecv)) {

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### CORE 4.X START CORRECT MISCELANEOUS VALUES", "[groupID: $groupID] [op: $op]");
        wachthond($extdebug,1, "########################################################################");

        watchdog('civicrm_timing', base_microtimer("START segment 4.X CORRECT MISCELANEOUS VALUES"), NULL, WATCHDOG_DEBUG);
    }

    ##########################################################################################
    if ($extchk == 1 AND in_array($groupID, $profilecv)) {    // PROFILE CONT + PART (BASIC)
    ##########################################################################################

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### CORE 4.1 CORE SYNC NAAR TRAININGSDAG","[groupID: $groupID] [op: $op]");
        wachthond($extdebug,2, "########################################################################");

        // De trainingsdag-registratie wordt aangemaakt door ACL 10.0 in acl_civicrm_configure(),
        // die verderop in deze functie wordt aangeroepen. Na aanmaken verrijkt de training
        // extensie (training_civicrm_post) het participant-record automatisch met kampdata.

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.1 CONSTRUCT (DRUPAL) USERNAME","[groupID: $groupID] [op: $op]");
    wachthond($extdebug,2, "########################################################################");

    wachthond($extdebug,3, 'contact_id',        $contact_id);
    wachthond($extdebug,3, 'first_name',        $first_name);
    wachthond($extdebug,3, 'middle_name',       $middle_name);
    wachthond($extdebug,3, 'last_name',         $last_name);
    wachthond($extdebug,3, 'nick_name',         $nick_name);
    wachthond($extdebug,2, 'displayname',       $displayname);

    watchdog('civicrm_timing', base_microtimer("START configure drupal username"), NULL, WATCHDOG_DEBUG);
    $array_username             = drupal_civicrm_username($contact_id, $first_name, $middle_name, $last_name, $displayname, $nick_name);
    watchdog('civicrm_timing', base_microtimer("EINDE configure drupal username"), NULL, WATCHDOG_DEBUG);

    $first_name                 = $array_username['first_name'];
    $middle_name                = $array_username['middle_name'];
    $last_name                  = $array_username['last_name'];
    $nick_name                  = $array_username['nick_name']               ?? NULL;

    $user_name                  = $array_username['user_name'];
    $user_name_nick             = $array_username['user_name_nick'];

    $displayname                = $array_username['displayname'];
    $contactname                = $array_username['contactname'];
    $familienaam                = $array_username['familienaam'];

    $valid_username             = $array_username['valid_username'];
    $valid_drupalid             = $array_username['valid_drupalid'];

    $need2update_extid          = $array_username['need2update_extid'];
    $need2update_jobtitle       = $array_username['need2update_jobtitle'];
    $need2repair_ufmatch        = $array_username['need2repair_ufmatch'];
    $need2update_ufmatch        = $array_username['need2update_ufmatch'];
    $need2create_ufmatch        = $array_username['need2create_ufmatch'];
    $safe2update_ufmatch        = $array_username['safe2update_ufmatch'];
    $safe2create_ufmatch        = $array_username['safe2create_ufmatch'];

    wachthond($extdebug,2, 'array_username',    $array_username);

    wachthond($extdebug,3, 'first_name',        $first_name);
    wachthond($extdebug,3, 'middle_name',       $middle_name);
    wachthond($extdebug,3, 'last_name',         $last_name);

    wachthond($extdebug,3, 'displayname',       $displayname);
    wachthond($extdebug,3, "contactname",       $contactname);
    wachthond($extdebug,3, "familienaam",       $familienaam);

    wachthond($extdebug,3, "user_name",         $user_name);
    wachthond($extdebug,3, "user_name_nick",    $user_name_nick);

    wachthond($extdebug,3, "valid_username",    $valid_username);
    wachthond($extdebug,3, "valid_drupalid",    $valid_drupalid);        

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.2 CONFIGURE ACCOUNT / ONETIME LOGIN / CHECKSUM",   "[$displayname]");
    wachthond($extdebug,2, "########################################################################");

    watchdog('civicrm_timing', base_microtimer("START configure account"), NULL, WATCHDOG_DEBUG);
    $account_array = account_civicrm_configure($contact_id);
    wachthond($extdebug,3, "account_array",             $account_array);
    watchdog('civicrm_timing', base_microtimer("EINDE configure account"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.3 CONFIGURE ACL GROUP MEMBERSCHIP AND PERMISSIONS", "[$birth_date]");
    wachthond($extdebug,2, "########################################################################");

    wachthond($extdebug,4, "array_contditjaar",         $array_contditjaar);
    wachthond($extdebug,4, "ditjaar_array",             $ditjaar_array);
    wachthond($extdebug,4, "array_allpart_ditjaar",     $array_allpart_ditjaar);
    wachthond($extdebug,4, "valid_drupalid",            $valid_drupalid);
    wachthond($extdebug,4, "ditjaar_rollen_array",      $ditjaar_rollen_array);

    watchdog('civicrm_timing', base_microtimer("START configure ACL"), NULL, WATCHDOG_DEBUG);
    $aclresult = acl_civicrm_configure($contact_id, $array_contditjaar, $ditjaar_array, $array_allpart_ditjaar, $valid_drupalid, $ditjaar_rollen_array);
    watchdog('civicrm_timing', base_microtimer("EINDE configure ACL"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.4 GET EMAILADRESSS TBV NOTIFICATIES");
    wachthond($extdebug,2, "########################################################################");

    ############################ BIJ 6.7 STELEN WE DE NOTIF_EMAILS IN N.A.V. DE VOORKEUREN ###########

    wachthond($extdebug,4, 'array_contditjaar',         $array_contditjaar);
    wachthond($extdebug,4, 'ditjaar_array',             $ditjaar_array);
    wachthond($extdebug,4, 'array_partditjaar',         $array_partditjaar);

    watchdog('civicrm_timing', base_microtimer("START configure EMAIL"), NULL, WATCHDOG_DEBUG);
    $array_email        = email_civicrm_configure($array_contditjaar, $ditjaar_array, $array_partditjaar, $datum_belangstelling);
    watchdog('civicrm_timing', base_microtimer("EINDE configure EMAIL"), NULL, WATCHDOG_DEBUG);

    $user_mail          = $array_email['user_mail']                     ?? NULL;
    $email_home_email   = $array_email['email_home_email']              ?? NULL;
    $email_priv_email   = $array_email['email_priv_email']              ?? NULL;

    wachthond($extdebug,3, "user_mail",                 $user_mail);
    wachthond($extdebug,3, "email_home_email",          $email_home_email);
    wachthond($extdebug,3, "email_priv_email",          $email_priv_email);

    wachthond($extdebug,4, "########################################################################");   
    wachthond($extdebug,3, "RECEIVE array_email",                                        $array_email);
    wachthond($extdebug,4, "########################################################################");

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.5 CONFIGURE CONNECTED DRUPAL ACCOUNT",         "[$birth_date]");
    wachthond($extdebug,2, "########################################################################");

    wachthond($extdebug,4, 'ditjaar_array',             $ditjaar_array);
    wachthond($extdebug,4, 'array_partditjaar',         $array_partditjaar);
    wachthond($extdebug,4, 'array_allpart_ditjaar',     $array_allpart_ditjaar);
    wachthond($extdebug,1, 'user_mail',                 $user_mail);

    watchdog('civicrm_timing', base_microtimer("START configure drupal account"), NULL, WATCHDOG_DEBUG);
    drupal_civicrm_configure($contact_id, $displayname, $user_mail, $ditjaar_array, $array_allpart_ditjaar);
    watchdog('civicrm_timing', base_microtimer("EINDE configure drupal account"), NULL, WATCHDOG_DEBUG);

    // M61: EXTRA HIER (NOG EEN KEER) EMAIL / DRUPAL / EMAIL
    watchdog('civicrm_timing', base_microtimer("START configure EMAIL 2"), NULL, WATCHDOG_DEBUG);
    $array_email        = email_civicrm_configure($array_contditjaar, $ditjaar_array, $array_partditjaar, $datum_belangstelling);
    watchdog('civicrm_timing', base_microtimer("EINDE configure EMAIL 2"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.6 DEFINE POSTAL & EMAIL GREETING", "[groupID: $groupID] [op: $op]");
    wachthond($extdebug,2, "########################################################################");

    watchdog('civicrm_timing', base_microtimer("START configure GREETING"), NULL, WATCHDOG_DEBUG);
    $array_greeting = email_civicrm_greeting($array_contditjaar, $ditjaar_array, $array_partditjaar);
    watchdog('civicrm_timing', base_microtimer("EINDE configure GREETING"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,4, 'RECEIVE array_cv', $array_cv);

    $email_greeting_id              = $array_greeting['email_greeting_id']                  ?? NULL;
    $communication_style_id         = $array_greeting['communication_style_id']             ?? NULL;

    wachthond($extdebug,3, 'email_greeting_id',         $email_greeting_id);
    wachthond($extdebug,3, 'communication_style_id',    $communication_style_id);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.8 CONFIGURE VAKANTIEREGIO SCHOOLVAKANTIE", "[$displayname]");
    wachthond($extdebug,2, "########################################################################");

    watchdog('civicrm_timing', base_microtimer("START configure REGIO"), NULL, WATCHDOG_DEBUG);
    $vakantieregio = werving_civicrm_vakantieregio($contact_id);
    watchdog('civicrm_timing', base_microtimer("EINDE configure REGIO"), NULL, WATCHDOG_DEBUG);
    wachthond($extdebug,1, "vakantieregio",       $vakantieregio);


    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.10 CONFIGURE AANDACHTSPUNTEN MEDISCH",              "MEDISCH]");
    wachthond($extdebug,2, "########################################################################");

    watchdog('civicrm_timing', base_microtimer("START configuratie MEDISCH"), NULL, WATCHDOG_DEBUG);
    $result_medisch = medisch_civicrm_configure($contact_id);
    wachthond($extdebug,3, 'result_medisch',                    $result_medisch);
    watchdog('civicrm_timing', base_microtimer("EINDE configuratie MEDISCH"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.11 CONFIGURE AANDACHTSPUNTEN GEDRAG",                "[GEDRAG]");
    wachthond($extdebug,2, "########################################################################");

    watchdog('civicrm_timing', base_microtimer("START configuratie GEDRAG"), NULL, WATCHDOG_DEBUG);
    $result_gedrag = gedrag_civicrm_configure($contact_id);
    wachthond($extdebug,3, 'result_gedrag',                     $result_gedrag);
    watchdog('civicrm_timing', base_microtimer("EINDE configuratie GEDRAG"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### CORE 4.12 CONFIGURE FOT, NAW, BIO", "[FOT/NAW/BIO]");
    wachthond($extdebug, 2, "########################################################################");

    // 1. Foto en algemene data mag altijd (of bij bredere scope)
    // Hier komt je bestaande code voor FOT/NAW/BIO verwerking...

    // 2. Alleen de ZWARE INTAKE aanroep conditioneel maken
    if (in_array($groupID, $profilecontmax)) {
        watchdog('civicrm_timing', base_microtimer("START configuratie INTAKE (Scope: Contact)"), NULL, WATCHDOG_DEBUG);
        
        $result_intake = intake_civicrm_configure($array_contditjaar ?? [], $array_partditjaar ?? [], $params);
        
        wachthond($extdebug, 3, 'result_intake', $result_intake);
        watchdog('civicrm_timing', base_microtimer("EINDE configuratie INTAKE"), NULL, WATCHDOG_DEBUG);
    } else {
        // We loggen specifiek dat we alleen de INTAKE-rekenmachine overslaan
        wachthond($extdebug, 1, "SKIP INTAKE REKENMACHINE: GroupID $groupID valt buiten PROFILE CV MAX. FOT/NAW wel verwerkt.");
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.13 ENSURE CUSTOM CONTACT FIELD TABLE ENTRIES",      "[ENSURE]");
    wachthond($extdebug,2, "########################################################################");

    watchdog('civicrm_timing', base_microtimer("START ensure entries contact"), NULL, WATCHDOG_DEBUG);
    $result_ensure_contact = ensure_custom_rows_for_contact($contact_id);
    wachthond($extdebug,3, 'result_ensure_contact',             $result_ensure_contact);
    watchdog('civicrm_timing', base_microtimer("EINDE ensure entries contact"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.14 ENSURE CUSTOM PARTICIPANT FIELD TABLE ENTRIES",  "[ENSURE]");
    wachthond($extdebug,2, "########################################################################");

    if ($ditjaar_pos_part_id) {
        watchdog('civicrm_timing', base_microtimer("START ensure entries part"), NULL, WATCHDOG_DEBUG);
        $result_ensure_participant = ensure_custom_rows_for_participant($ditjaar_pos_part_id);
        wachthond($extdebug,3, 'result_ensure_participant',     $result_ensure_participant);
        watchdog('civicrm_timing', base_microtimer("EINDE ensure entries part"), NULL, WATCHDOG_DEBUG);
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.14 ENSURE CUSTOM CONTRIBUTION FIELD TABLE ENTRIES", "[ENSURE]");
    wachthond($extdebug,2, "########################################################################");

    // M61: dit staat hier nu te vroeg. BID wordt pas in 5 bekeken.

    if ($ditevent_contribid) {
        $result_ensure_contribution = ensure_custom_rows_for_contribution($ditevent_contribid);
        wachthond($extdebug,3, 'result_ensure_contribution',     $result_ensure_contribution);
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.2 RUN CONTRIBUTION (PECUNIA) CONFIG",              "[CONTRIB]");
    wachthond($extdebug,2, "########################################################################");

    if (function_exists('pecunia_civicrm_configure')) {
        watchdog('civicrm_timing', base_microtimer("START configure pecunia"), NULL, WATCHDOG_DEBUG);
        // Let op: 4 parameters (contrib_id, contrib_array, part_array, context)
        $pecunia_array = pecunia_civicrm_configure(0, [], $array_partditjaar, 'direct'); 
        wachthond($extdebug, 3, "pecunia_array",      $pecunia_array);
        watchdog('civicrm_timing', base_microtimer("EINDE configure pecunia"), NULL, WATCHDOG_DEBUG);
    }

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.X EINDE CORRECT MISCELANEOUS VALUES", "[groupID: $groupID] [op: $op]");

  } else {
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.X SKIPPED CORRECT MISCELANEOUS VALUES", "[groupID: $groupID] [op: $op]");
  }

    watchdog('civicrm_timing', base_microtimer("EINDE segment 4.X CORRECT MISCELANEOUS VALUES"), NULL, WATCHDOG_DEBUG);

    ##########################################################################################
    # 4.X SEGMENT DEEL & LEID DIT EVENT (ELK JAAR) (OOK VOORGAANDE)
    ##########################################################################################

    if ($extacl == 1 AND in_array($groupID, $profilepart)) {

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### CORE 5.X SEGMENT DEEL & LEID DIT EVENT (ELK JAAR)", "[groupID: $groupID] [op: $op]");
        wachthond($extdebug,1, "########################################################################");

        watchdog('civicrm_timing', base_microtimer("START segment 5.X DEEL & LEID DIT EVENT"), NULL, WATCHDOG_DEBUG);

        wachthond($extdebug,3, 'diteventdeelyes',   $diteventdeelyes);
        wachthond($extdebug,3, 'diteventdeelmss',   $diteventdeelmss);
        wachthond($extdebug,3, 'diteventleidyes',   $diteventleidyes);
        wachthond($extdebug,3, 'diteventleidmss',   $diteventleidmss);

        wachthond($extdebug,3, 'ditjaardeelyes',    $ditjaardeelyes);
        wachthond($extdebug,3, 'ditjaardeelmss',    $ditjaardeelmss);
        wachthond($extdebug,3, 'ditjaarleidyes',    $ditjaarleidyes);
        wachthond($extdebug,3, 'ditjaarleidmss',    $ditjaarleidmss);

    }

    $part_kampkort = $part_kampkort ?? NULL;

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 5.5 SAVE CORRECT PARTICIPANT ROLE ID", "[$displayname $ditevent_part_functie $part_kampkort]");
    wachthond($extdebug,2, "########################################################################");

    wachthond($extdebug,4, 'part_eventtypeid',      $ditevent_event_type_id);

    wachthond($extdebug,4, 'eventypesdeel',         $eventtypesdeel);
    wachthond($extdebug,4, 'eventypesdeeltest',     $eventtypesdeeltest);
    wachthond($extdebug,4, 'eventypesdeeltop',      $eventtypesdeeltop);
    wachthond($extdebug,4, 'eventypesdeeltoptest',  $eventtypesdeeltoptest);
    wachthond($extdebug,4, 'eventypesleid',         $eventtypesleid);
    wachthond($extdebug,4, 'eventypesleidtest',     $eventtypesleidtest);
    wachthond($extdebug,4, 'eventypestoer',         $eventtypestoer);
    wachthond($extdebug,4, 'eventypesmeet',         $eventtypesmeet);

    // We gebruiken numerieke option-values (participant_role) om mismatches met labels te voorkomen.
    // LET OP: dit zijn de option_value.value-waarden uit de groep 'participant_role',
    // geverifieerd tegen de DB (25-jun-2026). De oude waarden (leiding=1/hoofdleiding=2/
    // deelnemer_top=11/leiding_top=15) waren FOUT — die wezen naar Bezoeker/(niet-bestaand)/
    // Cursist/Kampstaf en zorgden dat leiding als 'Bezoeker' werd weggeschreven.
    $rol_deelnemer      = 7;   // Deelnemer
    $rol_leiding        = 6;   // Leiding          (was fout: 1 = Bezoeker)
    $rol_hoofdleiding   = 12;  // Hoofdleiding     (was fout: 2 = bestaat niet)
    $rol_deelnemer_top  = 8;   // Deelnemer_Top    (was fout: 11 = Cursist)
    $rol_leiding_top    = 9;   // Leiding Topkamp  (was fout: 15 = Kampstaf)
    $rol_deelnemer_gave = 16;  // Deelnemer_Gave
    $rol_kampstaf       = 15;  // Kampstaf (toer/meet)

    $ditevent_rol_ids = [];

    // --- 1. DEELNEMER ROLLEN ---
    if (in_array($ditevent_event_type_id, $eventtypesdeel)) {
        $ditevent_rol_ids[] = $rol_deelnemer;
    }
    if (in_array($ditevent_event_type_id, $eventtypesdeeltest)) {
        $ditevent_rol_ids[] = $rol_deelnemer;
    }
    if (in_array($ditevent_event_type_id, $eventtypesdeeltoptest)) {
        $ditevent_rol_ids[] = $rol_deelnemer;
        $ditevent_rol_ids[] = $rol_deelnemer_top;
    }

    // --- 2. LEIDING ROLLEN ---
    // Echte leiding-events (Leiding Zomerkampen) → Leiding.
    // BEWUST 'leid' + 'leidtest' i.p.v. 'leid_all': leid_all bevat namelijk ook de
    // kampstafmeetings ('meet'), en die krijgen hieronder hun eigen rol (Kampstaf).
    if (in_array($ditevent_event_type_id, $eventtypesleid) ||
        in_array($ditevent_event_type_id, $eventtypesleidtest)) {
        $ditevent_rol_ids[] = $rol_leiding;
    }
    // Toerusting / trainingsdag ('toer' = train + workshop) → Leiding.
    // Dit zijn staf-events (leiding/kampstaf/bestuur); de afgesproken rol is Leiding (6).
    if (in_array($ditevent_event_type_id, $eventtypestoer)) {
        $ditevent_rol_ids[] = $rol_leiding;
    }
    // Kampstafmeetings ('meet') → Kampstaf.
    if (in_array($ditevent_event_type_id, $eventtypesmeet)) {
        $ditevent_rol_ids[] = $rol_kampstaf;
    }
    if (in_array($ditevent_part_functie, ['hoofdleiding'])) {
        $ditevent_rol_ids[] = $rol_hoofdleiding;
        $ditevent_rol_ids[] = $rol_leiding;
    }
    if ($ditevent_leid_welkkamp === 'TOP') {
        $ditevent_rol_ids[] = $rol_leiding_top;
    }

    // --- 3. SPECIALE ROLLEN BEHOUDEN (St. Gave = 16) ---
    // We kijken naar de rollen die Hussein NU al heeft in de database (of via St.Gave extensie)
    $huidige_rollen = is_array($ditevent_part_role_id) 
        ? $ditevent_part_role_id 
        : array_filter(explode(CRM_Core_DAO::VALUE_SEPARATOR, trim((string)$ditevent_part_role_id, CRM_Core_DAO::VALUE_SEPARATOR)));

    if (in_array(16, $huidige_rollen) || in_array('16', $huidige_rollen)) {
        $ditevent_rol_ids[] = $rol_deelnemer_gave; 
    }

    // --- 4. ARRAY OPSCHONEN EN OPSLAAN ---
    $ditevent_final_roles = array_values(array_unique(array_map('intval', $ditevent_rol_ids)));
    $params_part_ditevent['values']['role_id'] = $ditevent_final_roles;

    wachthond($extdebug, 1, 'DITEVENT ROLLEN (FINAAL)', $ditevent_final_roles);
    wachthond($extdebug, 1, 'ditevent_part_functie',    $ditevent_part_functie);
    wachthond($extdebug, 1, 'ditevent_part_rol',        $ditevent_part_rol);
    wachthond($extdebug, 1, 'ditevent_rol_id',          $ditevent_final_roles);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 5.6 CHECK OF ER SPRAKE IS VAN EEN VERJAARDAG TIJDENS DE KAMPWEEK", "[$birth_date]");
    wachthond($extdebug,2, "########################################################################");

    if ($birth_date AND $eventkamp_kampjaar > 0) {

        wachthond($extdebug,3, "birth_date",      $birth_date);

        $date_120yearsago = date('Y-m-d H:i:s', strtotime('-120 year', strtotime($today_datetime)) );
        wachthond($extdebug,3, "date_120yearsago",    $date_120yearsago);

        $valid_birthdate = date_bigger($birth_date,   $date_120yearsago);
        wachthond($extdebug,3, "valid_birthdate",     $valid_birthdate);

        if ($valid_birthdate == 1) {

            $birthdate_month  = date('m', strtotime($birth_date));
            $birthdate_day    = date('d', strtotime($birth_date));

            wachthond($extdebug,3, "birthdate_month",       $birthdate_month);
            wachthond($extdebug,3, "birthdate_day",         $birthdate_day);
            wachthond($extdebug,3, "eventkamp_kampjaar",    $eventkamp_kampjaar);

            $birthdate_ditjaar_object = date_create("$eventkamp_kampjaar-$birthdate_month-$birthdate_day");
            wachthond($extdebug,3, "birthdate_ditjaar_object",  $birthdate_ditjaar_object);

            $birthdate_ditjaar      = date_format($birthdate_ditjaar_object,"d-m-Y");
            wachthond($extdebug,3, "birthdate_ditjaar",     $birthdate_ditjaar);

            $verjaardagopkamp = date_between($birthdate_ditjaar, $eventkamp_event_start, $eventkamp_event_einde, 'verjaardag', 'kampdagen'); 
            $datebigger_birthday_eventstart = date_bigger($birthdate_ditjaar,     $eventkamp_event_start  );
            $datebigger_birthday_eventeinde = date_bigger($eventkamp_event_einde,   $birthdate_ditjaar    );

            wachthond($extdebug,3, "eventkamp_event_start", $eventkamp_event_start);
            wachthond($extdebug,3, "eventkamp_event_einde", $eventkamp_event_einde);

            wachthond($extdebug,3, "datebigger: birthday na   eventstart ?",  $datebigger_birthday_eventstart);
            wachthond($extdebug,3, "datebigger: birthday voor eventeinde ?",  $datebigger_birthday_eventeinde);

            if ($datebigger_birthday_eventstart == 1 AND $datebigger_birthday_eventeinde == 1) {
                $verjaardagopkamp = 1;
            }

            if ($verjaardagopkamp == 1) {
                wachthond($extdebug,1, "!!! DIT JAAR JARIG OP KAMP !!!", $birthdate_ditjaar);
                $params_part_ditevent['values']['PART.verjaardag'] = $birthdate_ditjaar;    
            }
        }
    }

    watchdog('civicrm_timing', base_microtimer("EINDE segment 5.X DEEL & LEID DIT EVENT"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE 5.X EINDE SEGMENT DITEVENT (ELK JAAR)", "[groupID: $groupID] [op: $op]");
    wachthond($extdebug,1, "########################################################################");

    ##########################################################################################
    if (in_array($groupID, $profilecv) AND $extdjcont == 1) {     // PROFILE CONT + PART (BASIC)
    ##########################################################################################

        watchdog('civicrm_timing', base_microtimer("START segment 8.X UPDATE PARAMS"), NULL, WATCHDOG_DEBUG);

        wachthond($extdebug,3, "########################################################################");
        wachthond($extdebug,3, "### CORE 8.X START UPDATE PARAMS CONTACT & PART", "[groupID: $groupID] [op: $op]");
        wachthond($extdebug,3, "########################################################################"); 

        wachthond($extdebug,3, 'diteventdeelyes',   $diteventdeelyes);
        wachthond($extdebug,3, 'diteventdeelmss',   $diteventdeelmss);
        wachthond($extdebug,3, 'diteventleidyes',   $diteventleidyes);
        wachthond($extdebug,3, 'diteventleidmss',   $diteventleidmss);

        wachthond($extdebug,3, 'ditjaardeelyes',    $ditjaardeelyes);
        wachthond($extdebug,3, 'ditjaardeelmss',    $ditjaardeelmss);
        wachthond($extdebug,3, 'ditjaarleidyes',    $ditjaarleidyes);
        wachthond($extdebug,3, 'ditjaarleidmss',    $ditjaarleidmss);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### CORE 8.0 PREPARE PARAMS FOR DB UPDATE",  "$displayname [CID: $contact_id]");
        wachthond($extdebug,2, "########################################################################");

        wachthond($extdebug,3, 'contact_id',            $contact_id);

        if ($contact_id > 0) {

            $params_contact = [
                #'reload'       => TRUE,
                'checkPermissions'  => FALSE,
                'debug' => $apidebug,
                'where' => [
                    ['id',       '=',  $contact_id],
                ],
                'values' => [
                    'id'            => $contact_id,
                    'display_name'  => $displayname,
                ],
            ];
        }

        wachthond($extdebug,3, 'ditevent_part_id',      $ditevent_part_id);

        if ($ditevent_part_id > 0) {

            $params_part_ditevent = [
                #'reload'       => TRUE,
                'checkPermissions'  => FALSE,
                'debug'       => $apidebug,
                'where' => [
                    ['id',  '=', $ditevent_part_id],
                ],
                'values' => [
                    'id'    =>   $ditevent_part_id,
                ],
            ];
        }

        wachthond($extdebug,3, 'ditjaar_prim_partid',   $ditjaar_prim_partid);

        if ($ditjaar_prim_partid > 0) {

            $params_part_ditjaar = [
                #'reload'       => TRUE,
                'checkPermissions'  => FALSE,
                'debug'       => $apidebug,
                'where' => [
                    ['id',  '=', $ditjaar_prim_partid],
                ],
                'values' => [
                    'id'  =>     $ditjaar_prim_partid,
                ],
            ];
        }    

        wachthond($extdebug,3, "params_contact",            $params_contact       ?? []);
        wachthond($extdebug,3, "params_part_ditevent",      $params_part_ditevent ?? []);
        wachthond($extdebug,3, "params_part_ditjaar",       $params_part_ditjaar  ?? []);

        ##########################################################################################
        # 8.1 UPDATE PARAMS_CONTACT (CV & DITJAAR) MET EVENT INFO - EN ANDERS LEEGMAKEN! (HIER MOET NOG EEN ELSIF DUS)
        ##########################################################################################

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### CORE 8.1 UPDATE PARAMS_CONTACT MET EVENT INFO", "[groupID: $groupID] [op: $op]");
        wachthond($extdebug,3, "########################################################################");       

        if (empty($ditjaar_part_groepsletter))      { $ditjaar_part_groepsletter   = "";   }
        if (empty($ditjaar_part_groepskleur))       { $ditjaar_part_groepskleur    = "";   }
        if (empty($ditjaar_part_groepsnaam))        { $ditjaar_part_groepsnaam     = "";   }
        if (empty($ditjaar_part_slaapzaal))         { $ditjaar_part_slaapzaal      = "";   }

        if (in_array($ditjaar_part_functie, array('hoofdleiding','kernteamlid'))) {

            $default_groepsletter   = 'Q';
            $default_groepskleur    = 'zilver';
            $default_groepsnaam     = 'Team Oempaloempa';

            // Deze code runt alleen als de datum van het huidige jaar < 20 juni
            if ($today_datetime < date("Y") . "-06-20 00:00:00") {

                if (empty($ditjaar_part_groepsletter))     { $ditjaar_part_groepsletter   = $default_groepsletter;  }
                if (empty($ditjaar_part_groepskleur))      { $ditjaar_part_groepskleur    = $default_groepskleur;   }
                if (empty($ditjaar_part_groepsnaam))       { $ditjaar_part_groepsnaam     = $default_groepsnaam;    }
            }

            // Deze code runt alleen als de datum van het huidige jaar > 1 juli
            if ($today_datetime > date("Y") . "-07-01 00:00:00") {

                if ($ditjaar_part_groepsletter == $default_groepsletter)   { $ditjaar_part_groepsletter   = "";  }
                if ($ditjaar_part_groepskleur  == $default_groepskleur)    { $ditjaar_part_groepskleur    = "";  }
                if ($ditjaar_part_groepsnaam   == $default_groepsnaam)     { $ditjaar_part_groepsnaam     = "";  }
            }

                $ditevent_part_groepsletter = $ditjaar_part_groepsletter;
                $ditevent_part_groepskleur  = $ditjaar_part_groepskleur;
                $ditevent_part_groepsnaam   = $ditjaar_part_groepsnaam;
        }

        #####################################################
        ### DIT JAAR (INDIEN DITJAAR ALL COUNT = 0)
        #####################################################

        if ($ditjaardeelnot == 1 AND $ditjaarleidnot == 1) {

            wachthond($extdebug,2, "########################################################################");
            wachthond($extdebug,1, "### CORE 8.1 a CLEAN UP VELDEN DITJAAR WANT DIT JAAR NIET MEE");
            wachthond($extdebug,3, "########################################################################");

            $params_contact['values']['DITJAAR.ditjaar_kamplang']           = ""; 
            $params_contact['values']['DITJAAR.ditjaar_kampkort']           = "";
            $params_contact['values']['DITJAAR.ditjaar_kamplocatie']        = "";
            $params_contact['values']['DITJAAR.ditjaar_kampplaats']         = "";

            $params_contact['values']['DITJAAR.ditjaar_event_start']        = "";
            $params_contact['values']['DITJAAR.ditjaar_event_end']          = "";
            $params_contact['values']['DITJAAR.ditjaar_kampjaar']           = "";
            $params_contact['values']['DITJAAR.ditjaar_weeknr']             = "";

            $params_contact['values']['DITJAAR.ditjaar_kamptype_naam']      = "";
            $params_contact['values']['DITJAAR.ditjaar_kamptype_id']        = "";
            $params_contact['values']['DITJAAR.ditjaar_rol']                = "";
            $params_contact['values']['DITJAAR.ditjaar_functie']            = "";

            $params_contact['values']['DITJAAR.ditjaar_groep_klas']         = "";
            $params_contact['values']['DITJAAR.ditjaar_voorkeur']           = "";

            $params_contact['values']['DITJAAR.ditjaar_groepsletter']       = "";
            $params_contact['values']['DITJAAR.ditjaar_groepskleur']        = "";
            $params_contact['values']['DITJAAR.ditjaar_groepsnaam']         = "";
            $params_contact['values']['DITJAAR.ditjaar_slaapzaal']          = "";

            $params_contact['values']['DITJAAR.ditjaar_cid']                = "";           
            $params_contact['values']['DITJAAR.ditjaar_pid']                = "";           
            $params_contact['values']['DITJAAR.ditjaar_eid']                = "";
            $params_contact['values']['DITJAAR.ditjaar_kid']                = "";

            $params_contact['values']['DITJAAR.ditjaar_hoofd_1_DN']         = "";
            $params_contact['values']['DITJAAR.ditjaar_hoofd_2_DN']         = "";
            $params_contact['values']['DITJAAR.ditjaar_hoofd_3_DN']         = "";
            $params_contact['values']['DITJAAR.ditjaar_hoofd_1_FN']         = "";
            $params_contact['values']['DITJAAR.ditjaar_hoofd_2_FN']         = "";
            $params_contact['values']['DITJAAR.ditjaar_hoofd_3_FN']         = "";
            $params_contact['values']['DITJAAR.ditjaar_HL1_IMG']            = "";
            $params_contact['values']['DITJAAR.ditjaar_HL2_IMG']            = "";
            $params_contact['values']['DITJAAR.ditjaar_HL3_IMG']            = "";     
            $params_contact['values']['DITJAAR.ditjaar_HL1_PH']             = "";
            $params_contact['values']['DITJAAR.ditjaar_HL2_PH']             = "";
            $params_contact['values']['DITJAAR.ditjaar_HL3_PH']             = "";
/*
            $params_contact['values']['DITJAAR.ditjaar_deelnamestatus']     = "";
            $params_contact['values']['DITJAAR.ditjaar_regdate']            = ""; 

            $params_contact['values']['DITJAAR.ditjaar_leeftijd']           = "";
            $params_contact['values']['DITJAAR.ditjaar_school']             = "";
            $params_contact['values']['DITJAAR.ditjaar_wachtlijst_erop']    = ""; 
            $params_contact['values']['DITJAAR.ditjaar_wachtlijst_eraf']    = ""; 
            $params_contact['values']['DITJAAR.ditjaar_criteria_indicatie'] = "";
            $params_contact['values']['DITJAAR.ditjaar_criteria_oordeel']   = "";
*/
            $params_contact['values']['DITJAAR.ditjaar_brengen_van']        = "";
            $params_contact['values']['DITJAAR.ditjaar_brengen_tot']        = "";
            $params_contact['values']['DITJAAR.ditjaar_pres_van']           = "";
            $params_contact['values']['DITJAAR.ditjaar_pres_tot']           = "";
            $params_contact['values']['DITJAAR.ditjaar_halen_van']          = "";
            $params_contact['values']['DITJAAR.ditjaar_halen_tot']          = "";

            $params_contact['values']['DITJAAR.ditjaar_thema_naam']         = "";
            $params_contact['values']['DITJAAR.ditjaar_thema_info']         = "";
            $params_contact['values']['DITJAAR.ditjaar_goeddoel_naam']      = "";
            $params_contact['values']['DITJAAR.ditjaar_goeddoel_info']      = "";
            $params_contact['values']['DITJAAR.ditjaar_goeddoel_link']      = "";

            $params_contact['values']['DITJAAR.ditjaar_welkomvideo']        = "";
            $params_contact['values']['DITJAAR.ditjaar_slotvideo']          = "";
            $params_contact['values']['DITJAAR.ditjaar_extrabagage']        = "";
            $params_contact['values']['DITJAAR.ditjaar_playlist']           = "";
            $params_contact['values']['DITJAAR.ditjaar_doc_link']           = "";
            $params_contact['values']['DITJAAR.ditjaar_doc_info']           = "";
            $params_contact['values']['DITJAAR.ditjaar_foto_vraag']         = "";
            $params_contact['values']['DITJAAR.ditjaar_foto_album']         = "";

            $params_contact['values']['DITJAAR.ditjaar_bid']                = "";
            $params_contact['values']['DITJAAR.ditjaar_bedrag']             = "";
            $params_contact['values']['DITJAAR.ditjaar_betaald']            = "";
            $params_contact['values']['DITJAAR.ditjaar_balans']             = "";
            $params_contact['values']['DITJAAR.ditjaar_regeling']           = "";

            $params_contact['values']['DITJAAR.ditjaar_fietshuur']          = "";

//          $params_contact['values']['PRIVACY.notificatie_deel']           = "";
//          $params_contact['values']['PRIVACY.notificatie_leid']           = "";
//          $params_contact['values']['PRIVACY.notificatie_kamp']           = "";
//          $params_contact['values']['PRIVACY.notificatie_staf']           = "";

            $params_contact['values']['INTAKE.INT_nodig']                   = "";
            $params_contact['values']['INTAKE.INT_status']                  = "";
            $params_contact['values']['INTAKE.REF_nodig']                   = "";
            $params_contact['values']['INTAKE.REF_status']                  = "";
            $params_contact['values']['INTAKE.VOG_nodig']                   = "";
            $params_contact['values']['INTAKE.VOG_status']                  = "";



        } else {
            wachthond($extdebug,2, "########################################################################");
            wachthond($extdebug,1, "### CORE 8.1 a SKIPPED CLEAN VELDEN DITJAAR WANT DIT JAAR WEL MEE");
            wachthond($extdebug,3, "########################################################################");
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### CORE 8.1 b DIT JAAR ALTIJD UPDATEN");
        wachthond($extdebug,3, "########################################################################");

        if ($displayname) {
            $params_contact['values']['PRIVACY.naam']                       = $displayname;       
        }

        if ($familienaam) {
            $params_contact['values']['PRIVACY.familienaam']                = $familienaam;
        }

        // WERVING-leeftijdsvelden worden al berekend en geschreven door de werving-extensie
        // (werving_civicrm_configure → new_leeftijd_decimalen, new_nextkamp_decimalen, new_vakantieregio).
        // Core schrijft ze hier niet meer: anders triggert de Contact.update op WERVING (group 270)
        // opnieuw civicrm_custom → potentiële loop als WERVING ooit in profilecvmax komt.
        // Verwijderd door: refactor leeftijd-verantwoordelijkheid naar werving-extensie.
        //
        // if ($leeftijd_nextkamp_decimalen > 0) {
        //     $params_contact['values']['WERVING.nextkamp_decimalen']     = $leeftijd_nextkamp_decimalen;
        //     $params_contact['values']['WERVING.nextkamp_rondjaren']     = $leeftijd_nextkamp_rondjaren;
        //     $params_contact['values']['WERVING.nextkamp_rondmaand']     = $leeftijd_nextkamp_rondmaand;
        // }
        // if ($leeftijd_vantoday_decimalen > 0) {
        //     $params_contact['values']['WERVING.leeftijd_decimalen']     = $leeftijd_vantoday_decimalen;
        //     $params_contact['values']['WERVING.leeftijd_rondjaren']     = $leeftijd_vantoday_rondjaren;
        // }
        // if (empty($werving_vakantieregio) AND $vakantieregio) {
        //     $params_contact['values']['WERVING.vakantieregio']          = $vakantieregio;
        // }

        if ($contact_foto)      { $params_contact['values']['image_URL']    = $contact_foto;    }

        ################################################################
        ### DIT JAAR ALTIJD UPDATEN (ALS ER EEN PRIMAIRE REGISTRATIE IS)
        ################################################################

        if ($ditjaar_prim_eventid > 0) {

            wachthond($extdebug,1, "########################################################################");
            wachthond($extdebug,1, "### CORE 8.1 c DIT JAAR ALTIJD UPDATEN (ALS ER EEN PRIMAIRE REGISTRATIE IS");
            wachthond($extdebug,3, "########################################################################");

            $params_contact['values']['DITJAAR.ditjaar_kamplang']               = $ditjaar_event_kampnaam;
            $params_contact['values']['DITJAAR.ditjaar_kampkort']               = $ditjaar_event_kampkort;
            $params_contact['values']['DITJAAR.ditjaar_kampsoort']              = $ditjaar_event_kampsoort;
            $params_contact['values']['DITJAAR.ditjaar_kampjaar']               = $ditjaar_event_kampjaar;
            $params_contact['values']['DITJAAR.ditjaar_weeknr']                 = $ditjaar_event_weeknr;          

            $params_contact['values']['DITJAAR.ditjaar_kamptype_naam']          = $ditjaar_event_kamptype_naam;
            $params_contact['values']['DITJAAR.ditjaar_kamptype_id']            = $ditjaar_event_kamptype_id;

            $params_contact['values']['DITJAAR.ditjaar_regdate']                = $ditjaar_part_register_date; 

            $params_contact['values']['DITJAAR.ditjaar_event_start']            = $ditjaar_event_start;
            $params_contact['values']['DITJAAR.ditjaar_event_end']              = $ditjaar_event_einde;
            $params_contact['values']['DITJAAR.ditjaar_kamplocatie']            = $ditjaar_event_pleklang;
            $params_contact['values']['DITJAAR.ditjaar_kampplaats']             = $ditjaar_event_stadlang;

            $params_contact['values']['DITJAAR.ditjaar_pid']                    = $ditjaar_prim_partid;
            $params_contact['values']['DITJAAR.ditjaar_eid']                    = $ditjaar_prim_eventid;
            $params_contact['values']['DITJAAR.ditjaar_cid']                    = $contact_id;

            $params_contact['values']['DITJAAR.ditjaar_rol']                    = $ditjaar_part_rol;
            $params_contact['values']['DITJAAR.ditjaar_functie']                = $ditjaar_part_functie;
        }

        ################################################################
        ### DIT JAAR UPDATEN (ALS ER EEN PRIMAIRE REGISTRATIE DEEL IS)
        ################################################################

        if ($ditjaar_prim_eventid > 0 AND in_array($ditjaar_prim_event_type_id, $eventtypesdeelall)) {

            wachthond($extdebug,2, "########################################################################");
            wachthond($extdebug,1, "### CORE 8.1 d DIT JAAR UPDATEN (IGV EEN PRIMAIRE REGISTRATIE DEEL)", "[DEEL]");
            wachthond($extdebug,3, "########################################################################");

            $params_contact['values']['DITJAAR.ditjaar_groep_klas']         = $ditjaar_part_groepklas;
            $params_contact['values']['DITJAAR.ditjaar_voorkeur']           = $ditjaar_part_voorkeur;

            wachthond($extdebug,3, 'ditjaar_part_groepklas',                $ditjaar_part_groepklas);
            wachthond($extdebug,3, 'ditjaar_part_voorkeur',                 $ditjaar_part_voorkeur);
        }

        ################################################################
        ### DIT JAAR UPDATEN (ALS ER EEN PRIMAIRE REGISTRATIE LEID IS)
        ################################################################

        if ($ditjaar_prim_eventid > 0 AND in_array($ditjaar_prim_event_type_id, $eventtypesleidall)) {

            wachthond($extdebug,2, "########################################################################");
            wachthond($extdebug,1, "### CORE 8.1 e DIT JAAR UPDATEN (IGV EEN PRIMAIRE REGISTRATIE LEID)", "[LEID]");
            wachthond($extdebug,3, "########################################################################");

            $params_contact['values']['DITJAAR.ditjaar_kid']                    = $eventkamp_event_id;

            // FIX: Maak álle criteria en check-datums expliciet leeg voor leiding
            $params_contact['values']['DITJAAR.ditjaar_leeftijd']               = "";
            $params_contact['values']['DITJAAR.ditjaar_school']                 = "";
            $params_contact['values']['DITJAAR.ditjaar_criteria_indicatie']     = "";
            $params_contact['values']['DITJAAR.ditjaar_criteria_oordeel']       = "";
            
            $params_contact['values']['DITJAAR.ditjaar_wachtlijst_erop']        = "";
            $params_contact['values']['DITJAAR.ditjaar_wachtlijst_eraf']        = "";
            $params_contact['values']['DITJAAR.ditjaar_criteriacheck_start']    = "";
            $params_contact['values']['DITJAAR.ditjaar_criteriacheck_einde']    = "";
        }

        #####################################################
        ### DIT EVENT & DIT JAAR DEEL = YES OF LEID = YES
        #####################################################

        if ($ditjaar_prim_eventid > 0 AND in_array($ditjaar_prim_status_id, $status_positive)) {

            wachthond($extdebug,2, "########################################################################");
            wachthond($extdebug,1, "### CORE 8.1 f DIT EVENT & DIT JAAR DEEL = YES OF LEID = YES",    "[POSITIVE]");
            wachthond($extdebug,3, "########################################################################");

            $params_contact['values']['DITJAAR.ditjaar_brengen_van']            = $eventkamp_brengen_van;
            $params_contact['values']['DITJAAR.ditjaar_brengen_tot']            = $eventkamp_brengen_tot;
            $params_contact['values']['DITJAAR.ditjaar_pres_van']               = $eventkamp_pres_van;
            $params_contact['values']['DITJAAR.ditjaar_pres_tot']               = $eventkamp_pres_tot;
            $params_contact['values']['DITJAAR.ditjaar_halen_van']              = $eventkamp_halen_van;
            $params_contact['values']['DITJAAR.ditjaar_halen_tot']              = $eventkamp_halen_tot;

            $params_contact['values']['DITJAAR.ditjaar_groepsletter']           = $ditjaar_part_groepsletter;
            $params_contact['values']['DITJAAR.ditjaar_groepskleur']            = $ditjaar_part_groepskleur;
            $params_contact['values']['DITJAAR.ditjaar_groepsnaam']             = $ditjaar_part_groepsnaam;
            $params_contact['values']['DITJAAR.ditjaar_slaapzaal']              = $ditjaar_part_slaapzaal;

            $params_contact['values']['DITJAAR.ditjaar_thema_naam']             = $ditjaar_thema_naam;
            $params_contact['values']['DITJAAR.ditjaar_thema_info']             = $ditjaar_thema_info;
            $params_contact['values']['DITJAAR.ditjaar_goeddoel_naam']          = $ditjaar_goeddoel_naam;
            $params_contact['values']['DITJAAR.ditjaar_goeddoel_info']          = $ditjaar_goeddoel_info;
            $params_contact['values']['DITJAAR.ditjaar_goeddoel_link']          = $ditjaar_goeddoel_link;

            $params_contact['values']['DITJAAR.ditjaar_welkomvideo']            = $ditjaar_welkomvideo;
            $params_contact['values']['DITJAAR.ditjaar_slotvideo']              = $ditjaar_slotvideo;
            $params_contact['values']['DITJAAR.ditjaar_extrabagage']            = $ditjaar_extrabagage;
            $params_contact['values']['DITJAAR.ditjaar_playlist']               = $ditjaar_playlist;
            $params_contact['values']['DITJAAR.ditjaar_doc_link']               = $ditjaar_doc_link;
            $params_contact['values']['DITJAAR.ditjaar_doc_info']               = $ditjaar_doc_info;
            $params_contact['values']['DITJAAR.ditjaar_foto_vraag']             = $ditjaar_foto_vraag;
            $params_contact['values']['DITJAAR.ditjaar_foto_album']             = $ditjaar_foto_album;

        }

        ################################################################
        ### IF EVENTID VAN DITEVENT == PRIMAIR EVENTID VAN DIT JAAR
        ################################################################

        if ($ditevent_part_eventid == $ditjaar_prim_eventid AND $ditevent_part_id == $ditjaar_prim_partid) {

            if (in_array($ditjaar_prim_event_type_id, $eventtypesall)) {

                wachthond($extdebug,2, "########################################################################");
                wachthond($extdebug,1, "### CORE 8.1 g IF DITEVENT == PRIMAIR EVENTID VAN DIT JAAR",      "[ALL]");
                wachthond($extdebug,3, "########################################################################");

                $params_contact['values']['DITJAAR.ditjaar_regdate']            = $ditevent_register_date; 
                $params_contact['values']['DITJAAR.ditjaar_rol']                = $ditjaar_part_rol;
                $params_contact['values']['DITJAAR.ditjaar_functie']            = $ditjaar_part_functie;
            }

        #####################################################
        ### DIT EVENT & DIT JAAR DEEL OF DIT JAAR LEID
        #####################################################

        $event_hoofdleiding2_displname  = $event_hoofdleiding2_displname  ?? NULL;
        $event_hoofdleiding2_firstname  = $event_hoofdleiding2_firstname  ?? NULL;
        $event_hoofdleiding2_phone      = $event_hoofdleiding2_phone      ?? NULL;
        $event_hoofdleiding2_image_bn   = $event_hoofdleiding2_image_bn   ?? NULL;
        $event_hoofdleiding3_displname  = $event_hoofdleiding3_displname  ?? NULL;
        $event_hoofdleiding3_firstname  = $event_hoofdleiding3_firstname  ?? NULL;
        $event_hoofdleiding3_phone      = $event_hoofdleiding3_phone      ?? NULL;
        $event_hoofdleiding3_image_bn   = $event_hoofdleiding3_image_bn   ?? NULL;

        if ($ditjaardeelyes == 1 OR $ditjaarleidyes == 1) {

            wachthond($extdebug,2, "########################################################################");
            wachthond($extdebug,1, "### CORE 8.1 i IF DITEVENT == PRIMAIR EVENTID VAN DIT JAAR", "[DITJAAR = YES]");
            wachthond($extdebug,3, "########################################################################");

            $params_contact['values']['DITJAAR.ditjaar_hoofd_1_DN']         = $event_hoofdleiding1_displname;
            $params_contact['values']['DITJAAR.ditjaar_hoofd_2_DN']         = $event_hoofdleiding2_displname;
            $params_contact['values']['DITJAAR.ditjaar_hoofd_3_DN']         = $event_hoofdleiding3_displname;
            $params_contact['values']['DITJAAR.ditjaar_hoofd_1_FN']         = $event_hoofdleiding1_firstname;
            $params_contact['values']['DITJAAR.ditjaar_hoofd_2_FN']         = $event_hoofdleiding2_firstname;
            $params_contact['values']['DITJAAR.ditjaar_hoofd_3_FN']         = $event_hoofdleiding3_firstname;
            $params_contact['values']['DITJAAR.ditjaar_HL1_PH']             = $event_hoofdleiding1_phone;
            $params_contact['values']['DITJAAR.ditjaar_HL2_PH']             = $event_hoofdleiding2_phone;
            $params_contact['values']['DITJAAR.ditjaar_HL3_PH']             = $event_hoofdleiding3_phone;
            $params_contact['values']['DITJAAR.ditjaar_HL1_IMG']            = $event_hoofdleiding1_image_bn;
            $params_contact['values']['DITJAAR.ditjaar_HL2_IMG']            = $event_hoofdleiding2_image_bn;
            $params_contact['values']['DITJAAR.ditjaar_HL3_IMG']            = $event_hoofdleiding3_image_bn;

//          $params_contact['values']['DITJAAR.ditjaar_fietshuur']          = $new_cont_fietshuur;
//          $params_contact['values']['DITJAAR.ditjaar_regeling']           = $new_kampgeldregeling;

        }

        if (in_array($ditevent_part_functie, array('hoofdleiding','kernteamlid'))) {

            wachthond($extdebug,2, "########################################################################");
            wachthond($extdebug,1, "### CORE 8.1 j IF DITEVENT == PRIMAIR EVENTID VAN DIT JAAR", "[KAMPSTAF]");
            wachthond($extdebug,3, "########################################################################");

            $params_contact['values']['PRIVACY.notificatie_deel']           = $ditevent_part_notificatie_deel;
            $params_contact['values']['PRIVACY.notificatie_leid']           = $ditevent_part_notificatie_leid;
            $params_contact['values']['PRIVACY.notificatie_kamp']           = $ditevent_part_notificatie_kamp;
            $params_contact['values']['PRIVACY.notificatie_staf']           = $ditevent_part_notificatie_staf;  
        }

        if ($ditevent_contribid > 0) {

            $params_contact['values']['DITJAAR.ditjaar_bid']                = $ditevent_contribid;
            $params_contact['values']['DITJAAR.ditjaar_bedrag']             = $saldo_bedrag;
            $params_contact['values']['DITJAAR.ditjaar_betaald']            = $saldo_betaald;
            $params_contact['values']['DITJAAR.ditjaar_balans']             = $saldo_balans;
        }
    }

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,3, 'params_cont_dusver', $params_contact);
    wachthond($extdebug,4, "########################################################################");

    ##########################################################################################
    # 8.2 UPDATE PARAMS_PARTICIPANT MET EVENT INFO
    if ($extdjpart == 1 AND in_array($groupID, $profilepart) AND $ditevent_part_id > 0) {   // PROFILE PART
    ##########################################################################################

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### CORE 8.2 UPDATE PARAMS_PARTICIPANT MET EVENTINFO ###", "[$eventkamp_kampkort $eventkamp_kampjaar]");
        wachthond($extdebug,3, "########################################################################");

        wachthond($extdebug,3, 'params_part_dusver',    $params_part_ditevent);

        #####################################################
        ### DIT EVENT DEEL + LEID [ALLES]
        #####################################################

        if ($displayname)           { $params_part_ditevent['values']['PART.PART_naam']       = $displayname;         }

        if (empty($ditjaar_part_vakantieregio) AND $vakantieregio) {
                $params_part_ditevent['values']['PART.vakantieregio']   = $vakantieregio;
        }

        if ($eventkamp_event_start)         { $params_part_ditevent['values']['PART.eventjaar']             = $eventkamp_event_start;       }
        if ($eventkamp_kampjaar)            { $params_part_ditevent['values']['PART.PART_kampjaar']         = $eventkamp_kampjaar;          }
        if ($eventkamp_event_weeknr)        { $params_part_ditevent['values']['PART.PART_kampweek_nr']      = $eventkamp_event_weeknr;      }
        if ($eventkamp_event_start)         { $params_part_ditevent['values']['PART.PART_kampstart']        = $eventkamp_event_start;       }
        if ($eventkamp_event_einde)         { $params_part_ditevent['values']['PART.PART_kampeinde']        = $eventkamp_event_einde;       }
        if ($eventkamp_pleklang)            { $params_part_ditevent['values']['PART.PART_kamplocatie']      = $eventkamp_pleklang;          }
        if ($eventkamp_stadlang)            { $params_part_ditevent['values']['PART.PART_kampplaats']       = $eventkamp_stadlang;          }

        if ($contact_id)                    { $params_part_ditevent['values']['PART.PART_cid']              = $contact_id;                  }

        if ($ditevent_part_id)              { $params_part_ditevent['values']['PART.PART_pid']              = $ditevent_part_id;            }
        if ($ditevent_part_eventid)         { $params_part_ditevent['values']['PART.PART_eid']              = $ditevent_part_eventid;       }
        if ($eventkamp_event_id)            { $params_part_ditevent['values']['PART.PART_kid']              = $eventkamp_event_id;          }
        if ($ditevent_contribid)            { $params_part_ditevent['values']['PART.PART_bid']              = $ditevent_contribid;          }

        if ($eventkamp_kamptype_naam)       { $params_part_ditevent['values']['PART.PART_kamptype_naam']    = $eventkamp_kamptype_naam;     }
        if ($eventkamp_kamptype_id)         { $params_part_ditevent['values']['PART.PART_kamptype_id']      = $eventkamp_kamptype_id;       }
        if ($eventkamp_kampnaam)            { $params_part_ditevent['values']['PART.PART_kamplang']         = $eventkamp_kampnaam;          }
        if ($eventkamp_kampkort)            { $params_part_ditevent['values']['PART.PART_kampkort']         = $eventkamp_kampkort;          }
        if ($eventkamp_kampsoort)           { $params_part_ditevent['values']['PART.PART_kampsoort']        = $eventkamp_kampsoort;         }

        if ($ditevent_part_groepsletter)    { $params_part_ditevent['values']['PART_INTERN.groep_letter']   = $ditevent_part_groepsletter;  }
        if ($ditevent_part_groepskleur)     { $params_part_ditevent['values']['PART_INTERN.groep_kleur']    = $ditevent_part_groepskleur;   }
        if ($ditevent_part_groepsnaam)      { $params_part_ditevent['values']['PART_INTERN.groep_naam']     = $ditevent_part_groepsnaam;    }

        if ($eventkamp_brengen_van)         { $params_part_ditevent['values']['PART_INTERN.brengen_van']    = $eventkamp_brengen_van;       }
        if ($eventkamp_brengen_tot)         { $params_part_ditevent['values']['PART_INTERN.brengen_tot']    = $eventkamp_brengen_tot;       }
        if ($eventkamp_pres_van)            { $params_part_ditevent['values']['PART_INTERN.pres_van']       = $eventkamp_pres_van;          }
        if ($eventkamp_pres_tot)            { $params_part_ditevent['values']['PART_INTERN.pres_tot']       = $eventkamp_pres_tot;          }
        if ($eventkamp_halen_van)           { $params_part_ditevent['values']['PART_INTERN.halen_van']      = $eventkamp_halen_van;         }
        if ($eventkamp_halen_tot)           { $params_part_ditevent['values']['PART_INTERN.halen_tot']      = $eventkamp_halen_tot;         }

        if ($eventkamp_thema_naam)          { $params_part_ditevent['values']['PART_INTERN.thema_naam']     = $eventkamp_thema_naam;        }
        if ($eventkamp_thema_info)          { $params_part_ditevent['values']['PART_INTERN.thema_info']     = $eventkamp_thema_info;        }
        if ($eventkamp_goeddoel_naam)       { $params_part_ditevent['values']['PART_INTERN.goeddoel_naam']  = $eventkamp_goeddoel_naam;     }
        if ($eventkamp_goeddoel_info)       { $params_part_ditevent['values']['PART_INTERN.goeddoel_info']  = $eventkamp_goeddoel_info;     }
        if ($eventkamp_goeddoel_link)       { $params_part_ditevent['values']['PART_INTERN.goeddoel_link']  = $eventkamp_goeddoel_link;     }

        if ($eventkamp_welkomvideo)         { $params_part_ditevent['values']['PART_INTERN.welkomvideo']    = $eventkamp_welkomvideo;       }
        if ($eventkamp_slotvideo)           { $params_part_ditevent['values']['PART_INTERN.slotvideo']      = $eventkamp_slotvideo;         }
        if ($eventkamp_extrabagage)         { $params_part_ditevent['values']['PART_INTERN.extrabagage']    = $eventkamp_extrabagage;       }
        if ($eventkamp_playlist)            { $params_part_ditevent['values']['PART_INTERN.playlist']       = $eventkamp_playlist;          }
        if ($eventkamp_doc_link)            { $params_part_ditevent['values']['PART_INTERN.doc_link']       = $eventkamp_doc_link;          }
        if ($eventkamp_doc_info)            { $params_part_ditevent['values']['PART_INTERN.doc_info']       = $eventkamp_doc_info;          }
        if ($eventkamp_foto_vraag)          { $params_part_ditevent['values']['PART_INTERN.foto_vraag']     = $eventkamp_foto_vraag;        }
        if ($eventkamp_foto_album)          { $params_part_ditevent['values']['PART_INTERN.foto_album']     = $eventkamp_foto_album;        }

        if ($leeftijd_ditevent_decimalen)   {
            $params_part_ditevent['values']['PART.nextkamp_decimalen']  = $leeftijd_ditevent_decimalen;
            $params_part_ditevent['values']['PART.nextkamp_rondjaren']  = $leeftijd_ditevent_rondjaren;
            $params_part_ditevent['values']['PART.nextkamp_rondmaand']  = $leeftijd_ditevent_rondmaand;
        }

        if ($ditevent_register_date)        { $params_part_ditevent['values']['PART.regdate']               = $ditevent_register_date;      }
        if ($ditevent_part_rol)             { $params_part_ditevent['values']['PART.PART_kamprol']          = $ditevent_part_rol;           }
        if ($ditevent_part_functie)         { $params_part_ditevent['values']['PART.PART_kampfunctie']      = $ditevent_part_functie;       }
        $ditevent_rol_id = $ditevent_rol_id ?? NULL;
        if ($ditevent_rol_id)               { $params_part_ditevent['values']['role_id:name']               = $ditevent_rol_id;             }

        if ($event_hoofdleiding1_displname) {
            $params_part_ditevent['values']['PART.PART_hoofd_1_dn'] = $event_hoofdleiding1_displname;
            $params_part_ditevent['values']['PART.PART_hoofd_1_fn'] = $event_hoofdleiding1_firstname;
        }
        if ($event_hoofdleiding2_displname) {
            $params_part_ditevent['values']['PART.PART_hoofd_2_dn'] = $event_hoofdleiding2_displname;
            $params_part_ditevent['values']['PART.PART_hoofd_2_fn'] = $event_hoofdleiding2_firstname;
        }
        if ($event_hoofdleiding3_displname) {
            $params_part_ditevent['values']['PART.PART_hoofd_3_dn'] = $event_hoofdleiding3_displname;
            $params_part_ditevent['values']['PART.PART_hoofd_3_fn'] = $event_hoofdleiding3_firstname;
        }

//      if ($toeristenbelasting)    { $params_part_ditevent['values']['PART_KAMPGELD.toeristenbelasting'] = $toeristenbelasting;    }
//      if ($new_kampgeldregeling)  { $params_part_ditevent['values']['PART_KAMPGELD.regeling']           = $new_kampgeldregeling;    }
//      if ($new_part_fietshuur)    { $params_part_ditevent['values']['PART_KAMPGELD.fietshuur']          = $new_part_fietshuur;      }

        if ($ditevent_contribid)    { $params_part_ditevent['values']['PART_KAMPGELD.contribid']        = $ditevent_contribid;      }

        $params_part_ditevent['values']['PART_KAMPGELD.bedrag']     = $saldo_bedrag;
        $params_part_ditevent['values']['PART_KAMPGELD.betaald']    = $saldo_betaald;
        $params_part_ditevent['values']['PART_KAMPGELD.balance']    = $saldo_balans;

        wachthond($extdebug,3, 'params_part_dusver',    $params_part_ditevent);

        #####################################################
        ### DIT EVENT DEEL [YES + MSS + TST]
        #####################################################

        if ($diteventdeelyes == 1 OR $diteventdeelmss == 1 OR $diteventdeeltst == 1) {

            if ($ditevent_part_1stdeel) {
                $params_part_ditevent['values']['PART.PART_1xkeer_deel']               = $ditevent_part_1stdeel;
            }
        }

        #####################################################
        ### DIT EVENT LEID [YES + MSS + TST]
        #####################################################

        if (in_array($ditevent_part_functie, array('hoofdleiding','kernteamlid','bestuur'))) {
            $params_part_ditevent['values']['PART_LEID_HOOFD.notificatie_deel'] = $ditevent_part_notificatie_deel;
            $params_part_ditevent['values']['PART_LEID_HOOFD.notificatie_leid'] = $ditevent_part_notificatie_leid;
            $params_part_ditevent['values']['PART_LEID_HOOFD.notificatie_kamp'] = $ditevent_part_notificatie_kamp;
            $params_part_ditevent['values']['PART_LEID_HOOFD.notificatie_staf'] = $ditevent_part_notificatie_staf;
        } elseif ($ditevent_part_id > 0) {
            $params_part_ditevent['values']['PART_LEID_HOOFD.notificatie_deel'] = "";
            $params_part_ditevent['values']['PART_LEID_HOOFD.notificatie_leid'] = "";
            $params_part_ditevent['values']['PART_LEID_HOOFD.notificatie_kamp'] = "";
            $params_part_ditevent['values']['PART_LEID_HOOFD.notificatie_staf'] = "";     
        }         

    }

    wachthond($extdebug,4, 'params_part_dusver',    $params_part_ditevent);

    ##########################################################################################
    # 8.5 a RETRIEVE RELATED HOOFDLEIDING
    ##########################################################################################
    if ($extrel == 1 AND ($ditjaardeelyes == 1 OR $ditjaarleidyes == 1 OR $ditjaardeelmss == 1 OR $ditjaarleidmss == 1)) {
    // M61: hier van maken dat het ook op voorgaande jaren werkt
    ##########################################################################################
        if (in_array($groupID, $profilepart)) {     // PROFILE PART
            if (empty($related_hoofdleiding_relid)) {

                wachthond($extdebug,1, "### CORE 8.5a RETRIEVE RELATED HOOFDLEIDING ###", "[groupID: $groupID] [op: $op]");

                $params_get_rel_hldn = [
                    'checkPermissions' => FALSE,
                    'debug' => $apidebug,
                    'select' => [
                        'row_count', 'contact_id_a', 'contact_id_b', 'is_active', 'start_date', 'end_date', 'id',
                    ],
                    'where' => [
                        ['contact_id_a',  '=',  $contact_id],
                        ['relationship_type_id','=', 17],
                        ['start_date',    '>=', $eventkamp_fiscalyear_start],
                        ['end_date',      '<=', $eventkamp_fiscalyear_einde],
                        ['is_active',       '=', TRUE],
                    ],
                ];

                wachthond($extdebug,7, 'params_get_rel_hldn',             $params_get_rel_hldn);
                $result_get_rel_hldn = civicrm_api4('Relationship','get', $params_get_rel_hldn);
                wachthond($extdebug,9, 'result_get_rel_hldn',             $result_get_rel_hldn);

                wachthond($extdebug,1, 'Related Hoofdleiding Relatie', "opgehaald");

                $result_get_rel_hldn_count      = $result_get_rel_hldn->countMatched();
                if ($result_get_rel_hldn_count == 1) {
                    $related_hoofdleiding_id    = $result_get_rel_hldn[0]['contact_id_b'] ?? NULL;
                    $related_hoofdleiding_relid = $result_get_rel_hldn[0]['id'] ?? NULL;
                } else {
                    $related_hoofdleiding_id    = NULL;
                    $related_hoofdleiding_relid = NULL;
                    wachthond($extdebug,1, "ERROR: GEEN RELATED HOOFDLEIDING GEVONDEN", "result_get_rel_hldn_count: $result_get_rel_hldn_count");
                }
                wachthond($extdebug,3, 'related_hoofdleiding_id',     $related_hoofdleiding_id);
                wachthond($extdebug,3, 'related_hoofdleiding_relid',  $related_hoofdleiding_relid);
            }
        }
    }
    ##########################################################################################
    # 8.5a CREATE RELATED HOOFDLEIDING
    ##########################################################################################
    if ($extrel == 1 AND ($ditjaardeelyes == 1 OR $ditjaarleidyes == 1 OR $ditjaardeelmss == 1 OR $ditjaarleidmss == 1)) {
        // M61: hier van maken dat het ook op voorgaande jaren werkt (is dat niet al zo door gebruik van event_fiscal_year?)
        ##########################################################################################

        wachthond($extdebug,1, "ditevent_part_kampkort", $ditevent_part_kampkort);

        if ($ditevent_part_kampkort == 'kk1')  { $related_hoofdleiding_id = 14197;}
        if ($ditevent_part_kampkort == 'kk2')  { $related_hoofdleiding_id = 14198;}
        if ($ditevent_part_kampkort == 'bk1')  { $related_hoofdleiding_id = 14199;}
        if ($ditevent_part_kampkort == 'bk2')  { $related_hoofdleiding_id = 14200;}
        if ($ditevent_part_kampkort == 'tk1')  { $related_hoofdleiding_id = 14201;}
        if ($ditevent_part_kampkort == 'tk2')  { $related_hoofdleiding_id = 14202;}
        if ($ditevent_part_kampkort == 'jk1')  { $related_hoofdleiding_id = 14203;}
        if ($ditevent_part_kampkort == 'jk2')  { $related_hoofdleiding_id = 14204;}
        if ($ditevent_part_kampkort == 'top')  { $related_hoofdleiding_id = 14205;}

        if (in_array($groupID, $profilepart)) {   // PROFILE PART
        if (empty($related_hoofdleiding_relid) AND $related_hoofdleiding_id > 0) {

          wachthond($extdebug,1, "### CORE 8.5b CREATE RELATED HOOFDLEIDING ###", "[groupID: $groupID] [op: $op]");

          $params_create_rel_hldn = [
            'checkPermissions' => FALSE,
            'debug' => $apidebug,
              'values' => [
                  'contact_id_a'            => $contact_id,
                  'contact_id_b'            => $related_hoofdleiding_id,
                  'relationship_type_id'    => 17,
                  'start_date'              => $eventkamp_fiscalyear_start,
                  'end_date'                => $eventkamp_fiscalyear_einde,
                  'is_active'               => 1,
                ],
              ];
          wachthond($extdebug,7, 'params_create_rel_hldn',          $params_create_rel_hldn);
          $result_create_rel_hldn = civicrm_api4('Relationship', 'create',  $params_create_rel_hldn);
          wachthond($extdebug,9, 'result_create_rel_hldn',          $result_create_rel_hldn);

          wachthond($extdebug,1, "Related Hoofdleiding Relatie", "aangemaakt");
            }
        }
      }

    ##########################################################################################
    # 8.5c UPDATE RELATED HOOFDLEIDING
    ##########################################################################################
    if ($extrel == 1 AND ($ditjaardeelyes == 1 OR $ditjaarleidyes == 1 OR $ditjaardeelmss == 1 OR $ditjaarleidmss == 1)) {
        // M61: hier van maken dat het ook op voorgaande jaren werkt
        ##########################################################################################
        if (in_array($groupID, $profilepart)) {   // PROFILE PART
            if ($related_hoofdleiding_relid > 0) {

                wachthond($extdebug,1, "### CORE 8.5c UPDATE RELATED HOOFDLEIDING ###", "[groupID: $groupID] [op: $op]");

                $params_update_rel_hldn = [
                    'checkPermissions' => FALSE,
                    'debug' => $apidebug,
                        'where' => [
                            ['id',                 '=', $related_hoofdleiding_relid],
                            ['contact_id_a',       '=', $contact_id],
                        ],
                        'values' => [
                            'id'                    =>  $related_hoofdleiding_relid,
                            'contact_id_a'          =>  $contact_id,
                            'contact_id_b'          =>  $related_hoofdleiding_id,
                            'relationship_type_id'  =>  17,
                            'start_date'            =>  $eventkamp_fiscalyear_start,
                            'end_date'              =>  $eventkamp_fiscalyear_einde,
                            'is_active'             =>  1,
                        ],
                ];
                wachthond($extdebug,7, 'params_update_rel_hldn',                $params_update_rel_hldn);
                $result_update_rel_hldn = civicrm_api4('Relationship','update', $params_update_rel_hldn);
                wachthond($extdebug,9, 'result_update_rel_hldn',                $result_update_rel_hldn);
                wachthond($extdebug,1, "Related Hoofdleiding Relatie", "bijgewerkt");
            }
        }
    }
        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### CORE 8.X EINDE UPDATE PARAMS CONTACT & PART ###", "[groupID: $groupID] [op: $op]");

    } else {
        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### CORE 8.X SKIPPED UPDATE PARAMS CONTACT & PART ###","[groupID: $groupID] [op: $op]");
    } 

    watchdog('civicrm_timing', base_microtimer("EINDE segment 8.X UPDATE PARAMS"), NULL, WATCHDOG_DEBUG);

    if ($params_contact OR $params_part_ditevent) {
        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### CORE 99. a WRITE CONTACT & PARTICIPANT DATA TO DB VOOR $displayname");
        wachthond($extdebug,1, "########################################################################");
    }

    if ($ditevent_part_rol == 'deelnemer') {
        wachthond($extdebug,2, 'diteventdeelyes',   $diteventdeelyes);
        wachthond($extdebug,2, 'diteventdeelmss',   $diteventdeelmss);
        wachthond($extdebug,2, 'diteventdeelnot',   $diteventdeelnot);
        wachthond($extdebug,2, 'ditjaardeelyes',    $ditjaardeelyes);
        wachthond($extdebug,2, 'ditjaardeelmss',    $ditjaardeelmss);
        wachthond($extdebug,2, 'ditjaardeelnot',    $ditjaardeelnot);
    }

    if ($ditevent_part_rol == 'leiding') {
        wachthond($extdebug,2, 'diteventleidyes',   $diteventleidyes);
        wachthond($extdebug,2, 'diteventleidmss',   $diteventleidmss);
        wachthond($extdebug,2, 'diteventleidnot',   $diteventleidnot);
        wachthond($extdebug,2, 'ditjaarleidyes',    $ditjaarleidyes);
        wachthond($extdebug,2, 'ditjaarleidmss',    $ditjaarleidmss);
        wachthond($extdebug,2, 'ditjaarleidnot',    $ditjaarleidnot);
    }

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE 99. b FINAL DATABASE QUERY PARAMS PREP",                "[PREP]");
    wachthond($extdebug,1, "########################################################################");

    if ($extwrite == 1 AND !empty($params_contrib) AND $ditevent_lineitem_contribid > 0 AND $ditevent_kampjaar > 2016) {

        $params_contrib['reload']               = TRUE;
        $params_contrib['checkPermissions']     = FALSE;
        $params_contrib['debug']                = $apidebug;
        wachthond($extdebug,1,  "contrib     DB UPDATE VOOR $displayname",
                    "bid: $ditevent_contribid \t/ $ditjaar_part_functie $ditjaar_part_kampkort $ditjaar_event_kampjaar [PREPARED]");
    }
    if ($extwrite == 1 AND !empty($params_contact) AND $contact_id > 0) {

        $params_contact['reload']               = TRUE;
        $params_contact['checkPermissions']     = FALSE;
        $params_contact['debug']                = $apidebug;
        wachthond($extdebug,1,  "contact     DB UPDATE VOOR $displayname",
                    "cid: $contact_id \t/ $ditjaar_part_functie $ditjaar_part_kampkort $ditjaar_event_kampjaar [PREPARED]");
    }
    if ($extwrite == 1 AND !empty($params_part_ditevent) AND $ditevent_part_id > 0) {

        $params_part_ditevent['reload']             = TRUE;
        $params_part_ditevent['checkPermissions']   = FALSE;
        $params_part_ditevent['debug']              = $apidebug;
        wachthond($extdebug,1,  "participant DB UPDATE VOOR $displayname",
                    "pid: $ditevent_part_id \t/ $ditevent_part_functie $eventkamp_kampkort $eventkamp_kampjaar (eid: $ditevent_part_eventid) [PREPARED]");
    }

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE 99. c FINAL DATABASE QUERY PARAMS SORT",                "[SORT]");
    wachthond($extdebug,1, "########################################################################");

    if ($extwrite == 1 AND !empty($params_contrib)     AND $ditevent_lineitem_contribid > 0 AND $ditevent_kampjaar > 2016) {
        wachthond($extdebug,4, 'params_contrib',        $params_contrib);
        ksort($params_contrib['values']);
        wachthond($extdebug,1, 'params_contrib',        $params_contrib);
    }

    if ($extwrite == 1 AND !empty($params_contact)     AND $contact_id > 0) {
        wachthond($extdebug,4, 'params_contact',        $params_contact);
        ksort($params_contact['values']);
        wachthond($extdebug,1, 'params_contact',        $params_contact);
    }

    if ($extwrite == 1 AND !empty($params_part_ditevent) AND $ditevent_part_id > 0) {
        wachthond($extdebug,4, 'params_part_ditevent',  $params_part_ditevent);
        ksort($params_part_ditevent['values']);
        wachthond($extdebug,1, 'params_part_ditevent',  $params_part_ditevent);
    }

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE 99. c FINAL DATABASE QUERY PARAMS CHECK",              "[CHECK]");
    wachthond($extdebug,1, "########################################################################");

    $params_contrib_where_id       = $params_contrib['where'][0][2]             ?? NULL;
    $params_contact_where_id       = $params_contact['where'][0][2]             ?? NULL;
    $params_part_ditevent_where_id = $params_part_ditevent['where'][0][2]       ?? NULL;

    wachthond($extdebug,2, "params_contrib_where_id",               "BID: $params_contrib_where_id");
    wachthond($extdebug,2, "params_contact_where_id",               "CID: $params_contact_where_id");
    wachthond($extdebug,2, "params_part_ditevent_where_id",         "PID: $params_part_ditevent_where_id");

    wachthond($extdebug,1, base_microtimer("DB Update voorbereid"));

    wachthond($extdebug,1, "########################################################################");

    // M61: WAAROM ALLEEN NA 2016?

    if (is_numeric($params_contrib_where_id)        AND $ditevent_contribid > 0 AND $ditevent_kampjaar > 2016)       {
        $extwrite_contrib       = 1;
        wachthond($extdebug,1, "PARAMS_CONTRIB  HEEFT CONTRIB_ID ($ditevent_contribid)","[DO QUERY]");
    } else {
        wachthond($extdebug,1, "PARAMS_CONTRIB  MIST CONTRIB_ID ",                      "[SKIP QUERY]");
    }

    if (is_numeric($params_contact_where_id)        AND $contact_id > 0)       {
        $extwrite_contact       = 1;
        wachthond($extdebug,1, "PARAMS_CONTACT  HEEFT CONTACT_ID ($contact_id)",        "[DO QUERY]");        
    } else {
        wachthond($extdebug,1, "PARAMS_CONTACT  MIST CONTACT_ID ",                      "[SKIP QUERY]");
    }

    if (is_numeric($params_part_ditevent_where_id)  AND $ditevent_part_id > 0) {
        $extwrite_part_ditevent = 1;
        wachthond($extdebug,1, "PARAMS_DITEVENT HEEFT PART_ID ($ditevent_part_id)",     "[DO QUERY]");                
    } else {
        wachthond($extdebug,1, "PARAMS_DITEVENT MIST PART_ID ",                         "[SKIP QUERY]");
    }

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE 99. d PERFORM DB UPDATE $displayname", "bid: $ditevent_contribid \t/ $ditevent_part_functie $eventkamp_kampkort $eventkamp_kampjaar");
    wachthond($extdebug,1, "########################################################################");

    $extwrite_contrib = $extwrite_contrib ?? FALSE;

    wachthond($extdebug,3, "extwrite_contrib",                      $extwrite_contrib);
    wachthond($extdebug,3, 'params_contrib',                        $params_contrib);

    watchdog('civicrm_timing', base_microtimer("START PERFORM DB UPDATE CONTRIBUTION"), NULL, WATCHDOG_DEBUG);

    if ($extwrite_contrib == 1) {

        $result_contrib = civicrm_api4('Contribution', 'update',    $params_contrib);

        wachthond($extdebug,1,  "contrib     DB UPDATE VOOR $displayname",  "[EXECUTED]");
    } else {
        wachthond($extdebug,1,  "contrib     DB UPDATE VOOR $displayname",  "[SKIPPED]");
    }

    watchdog('civicrm_timing', base_microtimer("EINDE PERFORM DB UPDATE CONTRIBUTION"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE 99. e PERFORM DB UPDATE $displayname",     "cid: $contact_id \t/ $ditjaar_part_functie $ditjaar_part_kampkort $ditjaar_event_kampjaar");
    wachthond($extdebug,1, "########################################################################");

    wachthond($extdebug,4, "extwrite_contact",                          $extwrite_contact);

    watchdog('civicrm_timing', base_microtimer("START PERFORM DB UPDATE CONT"), NULL, WATCHDOG_DEBUG);

    if (!empty($params_contact['values'])) {
        
        // 1. Haal de huidige waarden op uit de database om te vergelijken
        $params_contact_get = [
            'checkPermissions' => FALSE,
            'select' => array_keys($params_contact['values']),
            'where'  => [['id', '=', $contact_id]],
        ];
        $current_contact_data = civicrm_api4('Contact', 'get', $params_contact_get)->first();

        // 2. Filter: Bewaar alleen waarden die écht anders zijn
        $clean_values_contact = [];
        $has_changes_contact  = false;

        foreach ($params_contact['values'] as $key => $new_val) {
            if ($key === 'id') continue; // ID slaan we over in de check

            $old_val = $current_contact_data[$key] ?? '';

            // Vergelijk (niet-identiek != zodat '55' gelijk is aan 55)
            if ($new_val != $old_val) {
                $clean_values_contact[$key] = $new_val;
                $has_changes_contact = true;
                // Optioneel: Zet aan om te zien wat er verandert
                wachthond($extdebug, 3, "CHANGE DETECTED [$key]", "Oud: '$old_val' -> Nieuw: '$new_val'");
            }
        }

        // 3. Voer update alleen uit als er wijzigingen zijn
        if ($has_changes_contact) {
            // Zet het ID er weer bij, dat is verplicht voor update
            $clean_values_contact['id'] = $contact_id;
            
            // Overschrijf de originele params met de opgeschoonde lijst
            $params_contact['values'] = $clean_values_contact;

            $start_c = microtime(true);
            $result_contact = civicrm_api4('Contact', 'update', $params_contact);
            $dur_c = number_format(microtime(true) - $start_c, 3);

            wachthond($extdebug, 1, "contact     DB UPDATE VOOR $displayname", ": [EXECUTED] in $dur_c sec");
        } else {
            wachthond($extdebug, 1, "contact     DB UPDATE VOOR $displayname", ": [SKIPPED] - Geen wijzigingen");
        }

    } else {
        wachthond($extdebug, 1, "contact     DB UPDATE VOOR $displayname", ": [SKIPPED] - Geen params");
    }

    watchdog('civicrm_timing', base_microtimer("EINDE PERFORM DB UPDATE CONT"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE 99. f PERFORM DB UPDATE $displayname",     "pid: $ditevent_part_id \t/ $ditevent_part_functie $eventkamp_kampkort $eventkamp_kampjaar (eid: $ditevent_part_eventid)");
    wachthond($extdebug,1, "########################################################################");

    wachthond($extdebug,4, "extwrite_part_ditevent",                    $extwrite_part_ditevent);

    watchdog('civicrm_timing', base_microtimer("START PERFORM DB UPDATE PART"), NULL, WATCHDOG_DEBUG);

    if (!empty($params_part_ditevent['values']) && !empty($ditevent_part_id)) {

        // 1. Haal de huidige waarden op uit de database
        $params_part_get = [
            'checkPermissions' => FALSE,
            'select' => array_keys($params_part_ditevent['values']),
            'where'  => [['id', '=', $ditevent_part_id]],
        ];
        $current_part_data = civicrm_api4('Participant', 'get', $params_part_get)->first();

        // 2. Filter: Bewaar alleen waarden die écht anders zijn
        $clean_values_p = [];
        $has_changes_p  = false;

        foreach ($params_part_ditevent['values'] as $key => $new_val) {
            if ($key === 'id') continue; 

            $old_val = $current_part_data[$key] ?? '';

            // Vergelijk (niet-identiek !=)
            if ($new_val != $old_val) {
                $clean_values_p[$key] = $new_val;
                $has_changes_p = true;
                
                // BELANGRIJK: Laat deze regel even aan staan om te zien WAT er verandert
                wachthond($extdebug, 3, "CHANGE DETECTED PARTICIPANT [$key]", "Oud: '$old_val' -> Nieuw: '$new_val'");
            }
        }

        // 3. Update alleen bij wijziging
        if ($has_changes_p) {
            // ID toevoegen (verplicht)
            $clean_values_p['id'] = $ditevent_part_id;
            // Params overschrijven met alleen de wijzigingen
            $params_part_ditevent['values'] = $clean_values_p;

            $start_p = microtime(true);
            $result_part_ditevent = civicrm_api4('Participant', 'update', $params_part_ditevent);
            $dur_p = number_format(microtime(true) - $start_p, 3);

            wachthond($extdebug, 1, "participant DB UPDATE VOOR $displayname", ": [EXECUTED] in $dur_p sec");
        } else {
            wachthond($extdebug, 1, "participant DB UPDATE VOOR $displayname", ": [SKIPPED] - Geen wijzigingen");
        }

    } else {
        wachthond($extdebug, 1, "participant DB UPDATE VOOR $displayname", ": [SKIPPED] - Geen params of ID");
    }

    watchdog('civicrm_timing', base_microtimer("EINDE PERFORM DB UPDATE PART"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,9, "########################################################################");
    wachthond($extdebug,9, "### CORE 99. f FINAL DATABASE QUERY",                    "[SHOWRERSULTS]");
    wachthond($extdebug,9, "########################################################################");

    if ($extwrite == 1 AND !empty($params_contrib) AND $ditevent_contribid > 0 AND $ditevent_kampjaar > 2016) {
        wachthond($extdebug,9, 'result_contrib',        $result_contrib);
    }

    if ($extwrite == 1 AND !empty($params_contact) AND $contact_id > 0) {
        wachthond($extdebug,9, 'result_contact',        $result_contact);
    }

    if ($extwrite == 1 AND !empty($params_part_ditevent) AND $ditevent_part_id > 0) {
        wachthond($extdebug,9, 'result_part_ditevent',  $result_part_ditevent);
    }

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1,  "### CORE EINDE EXTENSION CV VOOR $displayname",
                                "[GroupID: $groupID] [op: $op] [entityID: $entityID]", 
                                "[$ditevent_part_functie: $displayname]");
    wachthond($extdebug,1, "########################################################################");
    // Bereken het verschil tussen NU en de START
    $totale_tijd = number_format(microtime(TRUE) - $start_tijd_script, 3);
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE EINDE PROCES VOOR $displayname");
    wachthond($extdebug,1, "### TOTALE VERWERKINGSTIJD: " . $totale_tijd . " sec");
    wachthond($extdebug,1, "########################################################################");    

}

/**
 * Implementation of hook_civicrm_config
 */
#function core_civicrm_config(&$config) {
# _core_civix_civicrm_config($config);
#}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */


/*
function core_civicrm_xmlMenu(&$files) {
  _core_civix_civicrm_xmlMenu($files);
}
*/


/**
 * Implementation of hook_civicrm_install
 */
function core_civicrm_install() {
  #CRM_Utils_File::sourceSQLFile(CIVICRM_DSN, __DIR__ . '/sql/auto_install.sql');
  return _core_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function core_civicrm_uninstall() {
  #CRM_Utils_File::sourceSQLFile(CIVICRM_DSN, __DIR__ . '/sql/auto_uninstall.sql');
  return _core_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function core_civicrm_enable() {
  return _core_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function core_civicrm_disable() {
  return _core_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */

/*
function core_civicrm_managed(&$entities) {
  return _core_civix_civicrm_managed($entities);
}
*/

