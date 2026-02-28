<?php

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

function core_civicrm_postCommit($op, $objectName, $objectId, &$objectRef) {

    $extdebug   = 0; // 1 = basic // 2 = verbose // 3 = params / 4 = results

    if ($objectName == 'XParticipant') {
        wachthond($extdebug,3, "########################################################################");
        wachthond($extdebug,1, "#### POSTCOMMIT ");
        wachthond($extdebug,3, "########################################################################");
        wachthond($extdebug,1, 'postCommit op',       $op);
        wachthond($extdebug,1, 'postCommit objectName',   $objectName);
        wachthond($extdebug,1, 'postCommit objectId',     $objectId);
        wachthond($extdebug,1, 'postCommit objectRef',    $objectRef);
        wachthond($extdebug,3, "########################################################################");
        wachthond($extdebug,1, 'postCommit Participant_ID', $objectId);
        wachthond($extdebug,3, "########################################################################");
    }

}

function core_civicrm_custom($op, $groupID, $entityID, &$params) {

    // Static variabele om dubbele executie in dezelfde request te voorkomen
    static $already_processed = [];
    
    // Alleen verwerken bij edit of create
    if ($op !== 'edit' && $op !== 'create') {
        return;
    }

    // Voorkom dubbele trigger voor dezelfde entiteit in één sessie
    if (isset($already_processed[$entityID . '_' . $groupID])) {
        return;
    }

    // Start de timer
    core_microtimer("Start Custom Hook");
    $start_tijd_script = microtime(TRUE);

    // VOEG DIT TOE: Repareer inkomende datums in de params array
    if (function_exists('drupal_timestamp_sweep')) {
        drupal_timestamp_sweep($params);
    }

    $extdebug   = 0;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
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

    $profilecont        = array(225); // JAAROVERZICHT
    $profilepartgeld    = array(281);
    $profilepartdeel    = array(139);
    $profilepartleid    = array(190);
    $profilepartref     = array(213);
    $profilepartvog     = array(140);
    $profilepart        = array_merge($profilepartdeel, $profilepartleid);

//  $profilepartintake    = array_merge($profilepartref,  $profilepartvog);
    $profilepartintake  = $profilepartvog;

    $profilecontmax     = array_merge($profilecont);
    $profilepartmax     = array_merge($profilepart,     $profilepartintake);
    $profilepartleidmax = array_merge($profilepartleid, $profilepartintake);
    $profilecv          = array_merge($profilecont,     $profilepart);
    $profilecvmax       = array_merge($profilecontmax,  $profilepartmax);

    if ($op != 'create' && $op != 'edit') { //    did we just create or edit a custom object?
        //    wachthond($extdebug,3, "########################################################################");
        //    wachthond($extdebug,2, "EXIT: op != create OR op != edit", "(op: $op)");
        //    wachthond($extdebug,3, "########################################################################");
        return; //  if not, get out of here
    }

    if (in_array($groupID, $profilepartref)) { // PROFILE LEID PART REF
        return;
    }

    if (in_array($groupID, $profilepartvog)) { // PROFILE LEID PART VOG
        return;
    }

    if (!in_array($groupID, $profilecvmax)) {
        return;
    }

if (in_array($groupID, $profilecvmax)) { // PROFILE CONT & PART (BASIC)

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,1, "### CORE 1.X START ONVERGETELIJK CORE", "[groupID: $groupID] [op: $op] [entityID: $entityID]");
    wachthond($extdebug,3, "########################################################################");

    watchdog('civicrm_timing', core_microtimer("START 1.X get variables"), NULL, WATCHDOG_DEBUG);

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

    if (in_array($groupID, $profilepartmax)) {  // PROFILE PARTICIPANT MAX

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

        watchdog('civicrm_timing', core_microtimer("START pid2part"), NULL, WATCHDOG_DEBUG);
        $array_partditevent = base_pid2part($part_id);
        watchdog('civicrm_timing', core_microtimer("EINDE pid2part"), NULL, WATCHDOG_DEBUG);

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

        $ditevent_eventid                   = $array_partditevent['part_event_id']                  ?? NULL;
        $ditevent_event_title               = $array_partditevent['event_title']                    ?? NULL;
        $ditevent_event_type_id             = $array_partditevent['event_type_id']                  ?? NULL;
        $ditevent_event_type_label          = $array_partditevent['event_type_label']               ?? NULL;

        $ditevent_register_date             = $array_partditevent['register_date']                  ?? NULL;
        $ditevent_event_start               = $array_partditevent['event_start_date']               ?? NULL;
        $ditevent_event_einde               = $array_partditevent['event_end_date']                 ?? NULL;
        $ditevent_kampjaar                  = $array_partditevent['part_kampjaar']                  ?? NULL;

        $ditevent_event_kampnaam            = $array_partditevent['kenmerken_kampnaam']             ?? NULL;
        $ditevent_event_kampkort            = $array_partditevent['kenmerken_kampkort']             ?? NULL;
        $ditevent_event_kampkort_low        = $array_partditevent['kenmerken_kampkort_low']         ?? NULL;
        $ditevent_event_kampkort_cap        = $array_partditevent['kenmerken_kampkort_cap']         ?? NULL;

        $ditevent_event_kamptype            = $array_partditevent['kenmerken_kamptype_naam']        ?? NULL;
        $ditevent_event_kamptype_naam       = $array_partditevent['kenmerken_kamptype_naam']        ?? NULL;
        $ditevent_event_kamptype_label      = $array_partditevent['kenmerken_kamptype_label']       ?? NULL;
        $ditevent_event_kamptype_id         = $array_partditevent['kenmerken_kamptype_id']          ?? NULL;
        $ditevent_event_kampsoort           = $array_partditevent['kenmerken_kampsoort']            ?? NULL;

        $ditevent_part_kampnaam             = $array_partditevent['part_kampnaam']                  ?? NULL;
        $ditevent_part_kampkort             = $array_partditevent['part_kampkort']                  ?? NULL;
        $ditevent_part_kampkort_low         = $array_partditevent['part_kampkort_low']              ?? NULL;
        $ditevent_part_kampkort_cap         = $array_partditevent['part_kampkort_cap']              ?? NULL;

        $ditevent_part_kamptype_id          = $array_partditevent['part_kamptype_id']               ?? NULL;
        $ditevent_part_functie              = $array_partditevent['part_functie']                   ?? NULL;
        $ditevent_part_rol                  = $array_partditevent['part_rol']                       ?? NULL;
        $ditevent_leid_welkkamp             = $array_partditevent['part_leid_kamp']                 ?? NULL;
        $ditevent_leid_functie              = $array_partditevent['part_leid_functie']              ?? NULL;

        $ditevent_event_brengen_van         = $array_partditevent['event_brengen_van']              ?? NULL;
        $ditevent_event_brengen_tot         = $array_partditevent['event_brengen_tot']              ?? NULL;
        $ditevent_event_pres_van            = $array_partditevent['event_pres_van']                 ?? NULL;
        $ditevent_event_pres_tot            = $array_partditevent['event_pres_tot']                 ?? NULL;
        $ditevent_event_halen_van           = $array_partditevent['event_halen_van']                ?? NULL;
        $ditevent_event_halen_tot           = $array_partditevent['event_halen_tot']                ?? NULL;

        $ditevent_event_thema_naam          = $array_partditevent['event_thema_naam']               ?? NULL;
        $ditevent_event_thema_info          = $array_partditevent['event_thema_info']               ?? NULL;
        $ditevent_event_goeddoel_naam       = $array_partditevent['event_goeddoel_naam']            ?? NULL;
        $ditevent_event_goeddoel_info       = $array_partditevent['event_goeddoel_info']            ?? NULL;
        $ditevent_event_goeddoel_link       = $array_partditevent['event_goeddoel_link']            ?? NULL;

        $ditevent_part_1stdeel              = $array_partditevent['part_1stdeel']                   ?? NULL;
        $ditevent_part_1stleid              = $array_partditevent['part_1stleid']                   ?? NULL;

        $ditevent_part_nawgecheckt          = $array_partditevent['part_nawgecheckt']               ?? NULL;
        $ditevent_part_biogecheckt          = $array_partditevent['part_biogecheckt']               ?? NULL;

        $org_ditpart_nawgecheckt            = $ditevent_part_nawgecheckt;
        $new_ditpart_nawgecheckt            = $ditevent_part_nawgecheckt;

        $org_ditpart_biogecheckt            = $ditevent_part_biogecheckt;
        $new_ditpart_biogecheckt            = $ditevent_part_biogecheckt;

        $ditevent_part_groepklas            = $array_partditevent['part_groepklas']             ?? NULL;
        $ditevent_part_voorkeur             = $array_partditevent['part_voorkeur']              ?? NULL;
        $ditevent_part_groepsletter         = $array_partditevent['part_groepsletter']          ?? NULL;
        $ditevent_part_groepskleur          = $array_partditevent['part_groepskleur']           ?? NULL;
        $ditevent_part_groepsnaam           = $array_partditevent['part_groepsnaam']            ?? NULL;
        $ditevent_part_slaapzaal            = $array_partditevent['part_slaapzaal']             ?? NULL;

        $ditevent_wachtlijst_erop           = $array_partditevent['part_wachtlijst_erop']       ?? NULL;
        $ditevent_wachtlijst_eraf           = $array_partditevent['part_wachtlijst_eraf']       ?? NULL;
        $ditevent_criteriacheck_start       = $array_partditevent['part_criteriacheck_start']   ?? NULL;
        $ditevent_criteriacheck_einde       = $array_partditevent['part_criteriacheck_einde']   ?? NULL;

        $ditevent_criteria_indicatie        = $array_partditevent['part_criteria_indicatie']    ?? NULL;
        $ditevent_criteria_oordeel          = $array_partditevent['part_criteria_oordeel']      ?? NULL;

        $new_ditevent_criteria_indicatie    = $ditevent_criteria_indicatie;
        $new_ditevent_criteria_indicatie    = $ditevent_criteria_oordeel;

        $ditevent_part_notificatie_deel     = $array_partditevent['part_notificatie_deel']      ?? NULL;
        $ditevent_part_notificatie_leid     = $array_partditevent['part_notificatie_leid']      ?? NULL;
        $ditevent_part_notificatie_kamp     = $array_partditevent['part_notificatie_kamp']      ?? NULL;
        $ditevent_part_notificatie_staf     = $array_partditevent['part_notificatie_staf']      ?? NULL;
        $ditevent_part_notificatie_priv     = $array_partditevent['part_notificatie_priv']      ?? NULL;

        $ditevent_part_kampgeld_contribid   = $array_partditevent['part_kampgeld_contribid']    ?? NULL;
        $ditevent_part_kampgeld_regeling    = $array_partditevent['part_kampgeld_regeling']     ?? NULL;
        $ditevent_part_kampgeld_fietshuur   = $array_partditevent['part_kampgeld_fietshuur']    ?? NULL;
        $ditevent_event_fietsevent          = $array_partditevent['event_fietsevent']           ?? NULL;

        $ditevent_part_evaluatie_datum      = $array_partditevent['part_evaluatie_datum']       ?? NULL;

        if (in_array($ditevent_event_type_id, $eventtypesleidall)) {
            $ditevent_part_vogverzocht      = $array_partditevent['part_vogverzocht']           ?? NULL;
            $ditevent_part_vogingediend     = $array_partditevent['part_vogingediend']          ?? NULL;
            $ditevent_part_vogontvangst     = $array_partditevent['part_vogontvangst']          ?? NULL;
            $ditevent_part_vogdatum         = $array_partditevent['part_vogdatum']              ?? NULL;
            $ditevent_part_vogkenmerk       = $array_partditevent['part_vogkenmerk']            ?? NULL;
        }
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

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 1.3 GET CONTACT INFO BASED ON CONTACTID",        "[CID: $contact_id]");
    wachthond($extdebug,3, "########################################################################");

    watchdog('civicrm_timing', core_microtimer("START cid2cont"), NULL, WATCHDOG_DEBUG);
    $array_contditjaar = base_cid2cont($contact_id);
    wachthond($extdebug,3, "array_contditjaar", $array_contditjaar);
    watchdog('civicrm_timing', core_microtimer("EINDE cid2cont"), NULL, WATCHDOG_DEBUG);

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
    $intakegesprekdatum         = $array_contditjaar['intakegesprekdatum']      ?? NULL;
    $intakegesprekpersoon       = $array_contditjaar['intakegesprekpersoon']    ?? NULL;

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

//  $org_ditjaar_nawgecheckt    = $array_contditjaar['org_ditjaar_nawgecheckt'] ?? NULL;  // ZET PARAMETERS INITIEEL MET WAARDE VAN DE OUDE
//  $new_ditjaar_nawgecheckt    = $array_contditjaar['new_ditjaar_nawgecheckt'] ?? NULL;  // ZET PARAMETERS INITIEEL MET WAARDE VAN DE OUDE
//  $org_ditjaar_biogecheckt    = $array_contditjaar['org_ditjaar_biogecheckt'] ?? NULL;  // ZET PARAMETERS INITIEEL MET WAARDE VAN DE OUDE
//  $new_ditjaar_biogecheckt    = $array_contditjaar['new_ditjaar_biogecheckt'] ?? NULL;  // ZET PARAMETERS INITIEEL MET WAARDE VAN DE OUDE

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
    wachthond($extdebug,3, 'entityID',                  $entityID);
    wachthond($extdebug,3, 'contact_id',                $contact_id);
    wachthond($extdebug,3, 'birth_date',                $birth_date);
    wachthond($extdebug,3, 'first_name',                $first_name);
    wachthond($extdebug,3, 'middle_name',               $middle_name);
    wachthond($extdebug,3, 'last_name',                 $last_name);
    wachthond($extdebug,3, 'nick_name',                 $nick_name);
    wachthond($extdebug,2, 'displayname',               $displayname);
    wachthond($extdebug,3, 'crm_drupalnaam',            $crm_drupalnaam);
    wachthond($extdebug,3, 'crm_externalid',            $crm_externalid);

    wachthond($extdebug,3, 'laatstekeer',               $laatste_keer);
    wachthond($extdebug,3, 'curcv_deel_array',          $curcv_deel_array);
    wachthond($extdebug,3, 'curcv_leid_array',          $curcv_leid_array);
    wachthond($extdebug,3, 'curcv_keer_deel',           $curcv_keer_deel);
    wachthond($extdebug,3, 'curcv_keer_leid',           $curcv_keer_leid);

    wachthond($extdebug,3, 'datum_belangstelling',      $datum_belangstelling);
    wachthond($extdebug,3, 'intakegesprekdatum',        $intakegesprekdatum);
    wachthond($extdebug,3, 'intakegesprekpersoon',      $intakegesprekpersoon);         

    wachthond($extdebug,3, 'werving_mee_komendkamp',    $werving_mee_komendkamp);
    wachthond($extdebug,3, 'werving_mee_verwachting',   $werving_mee_verwachting);
    wachthond($extdebug,3, 'werving_mee_toelichting',   $werving_mee_toelichting);
    wachthond($extdebug,3, 'werving_mee_update',        $werving_mee_update);
    wachthond($extdebug,3, 'werving_mee_update_year',   $werving_mee_update_year);
    wachthond($extdebug,3, 'werving_mee_notities',      $werving_mee_notities);

    wachthond($extdebug,3, 'ditjaar_nawgecheckt',       $ditjaar_nawgecheckt);
    wachthond($extdebug,3, 'ditjaar_bioingevuld',       $ditjaar_bioingevuld);
    wachthond($extdebug,3, 'ditjaar_biogecheckt',       $ditjaar_biogecheckt);
    wachthond($extdebug,3, 'org_ditjaar_nawgecheckt',   $org_ditjaar_nawgecheckt);
//  wachthond($extdebug,3, 'org_ditpart_nawgecheckt',   $org_ditpart_nawgecheckt);
    wachthond($extdebug,3, 'new_ditjaar_nawgecheckt',   $new_ditjaar_nawgecheckt);
//  wachthond($extdebug,3, 'new_ditpart_nawgecheckt',   $new_ditpart_nawgecheckt);

    wachthond($extdebug,3, 'privacy_voorkeuren',        $privacy_voorkeuren);
    wachthond($extdebug,3, 'privacy_geheimadres',       $privacy_geheimadres);
    wachthond($extdebug,3, 'privacy_beeldgebruik',      $privacy_beeldgebruik);

    wachthond($extdebug,3, 'cont_notificatie_deel',     $cont_notificatie_deel);
    wachthond($extdebug,3, 'cont_notificatie_leid',     $cont_notificatie_leid);
    wachthond($extdebug,3, 'cont_notificatie_kamp',     $cont_notificatie_kamp);
    wachthond($extdebug,3, 'cont_notificatie_staf',     $cont_notificatie_staf);

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

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 1.6 a CV DEEP DIVE CHECK DIT JAAR DEEL/LEID POS/ONE", "[partditjaar_all]");
    wachthond($extdebug,2, "########################################################################");

    watchdog('civicrm_timing', core_microtimer("START allpart"), NULL, WATCHDOG_DEBUG);
    $array_allpart_ditjaar              = base_find_allpart($contact_id, $today_datetime);
    watchdog('civicrm_timing', core_microtimer("EINDE allpart"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,2, "array_allpart_ditjaar",                            $array_allpart_ditjaar);
    wachthond($extdebug,4, "########################################################################");

    $ditjaar_refdate                    = $array_allpart_ditjaar['refdate'];
    $ditjaar_refyear                    = $array_allpart_ditjaar['refyear'];

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
    $ditjaar_neg_part_id                = $array_allpart_ditjaar['result_allpart_neg_part_id'];

    $ditjaar_wait_event_id              = $array_allpart_ditjaar['result_allpart_wait_event_id'];
    $ditjaar_pen_event_id               = $array_allpart_ditjaar['result_allpart_pen_event_id'];
    $ditjaar_neg_event_id               = $array_allpart_ditjaar['result_allpart_neg_event_id'];

    $ditjaar_wait_event_type_id         = $array_allpart_ditjaar['result_allpart_wait_event_type_id'];
    $ditjaar_pen_event_type_id          = $array_allpart_ditjaar['result_allpart_pen_event_type_id'];
    $ditjaar_neg_event_type_id          = $array_allpart_ditjaar['result_allpart_neg_event_type_id'];

    $ditjaar_wait_status_id             = $array_allpart_ditjaar['result_allpart_wait_status_id'];
    $ditjaar_pen_status_id              = $array_allpart_ditjaar['result_allpart_pen_status_id'];
    $ditjaar_neg_status_id              = $array_allpart_ditjaar['result_allpart_neg_status_id'];

    $ditjaar_wait_kampkort              = $array_allpart_ditjaar['result_allpart_wait_kampkort'];
    $ditjaar_pen_kampkort               = $array_allpart_ditjaar['result_allpart_pen_kampkort'];
    $ditjaar_neg_kampkort               = $array_allpart_ditjaar['result_allpart_neg_kampkort'];


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

    $array_allpart_eventjaar            = base_find_allpart($contact_id, $ditevent_event_start);

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
    $eventjaar_neg_part_id              = $array_allpart_eventjaar['result_allpart_neg_part_id'];

    $eventjaar_wait_event_id            = $array_allpart_eventjaar['result_allpart_wait_event_id'];
    $eventjaar_pen_event_id             = $array_allpart_eventjaar['result_allpart_pen_event_id'];
    $eventjaar_neg_event_id             = $array_allpart_eventjaar['result_allpart_neg_event_id'];

    $eventjaar_wait_event_type_id       = $array_allpart_eventjaar['result_allpart_wait_event_type_id'];
    $eventjaar_pen_event_type_id        = $array_allpart_eventjaar['result_allpart_pen_event_type_id'];
    $eventjaar_neg_event_type_id        = $array_allpart_eventjaar['result_allpart_neg_event_type_id'];

    $eventjaar_wait_status_id           = $array_allpart_eventjaar['result_allpart_wait_status_id'];
    $eventjaar_pen_status_id            = $array_allpart_eventjaar['result_allpart_pen_status_id'];
    $eventjaar_neg_status_id            = $array_allpart_eventjaar['result_allpart_neg_status_id'];

    $eventjaar_wait_kampkort            = $array_allpart_eventjaar['result_allpart_wait_kampkort'];
    $eventjaar_pen_kampkort             = $array_allpart_eventjaar['result_allpart_pen_kampkort'];
    $eventjaar_neg_kampkort             = $array_allpart_eventjaar['result_allpart_neg_kampkort'];

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
                  "$ditjaar_part_kampkort $ditjaar_refyear [eventid: $ditjaar_pos_event_id / partid: $ditjaar_pos_part_id]");
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
        $ditjaar_prim_status_id     = $ditjaar_wait_part_status_id;     
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
        $ditjaar_prim_status_id     = $ditjaar_pen_part_status_id;
        wachthond($extdebug,1,  "DITJAAR PEN - DITJAAR MAAR 1 PENDING REGISTRATIE", 
                  "$ditjaar_pen_kampkort $ditjaar_refyear [eventid: $ditjaar_pen_event_id / partid: $ditjaar_pen_part_id]");
        wachthond($extdebug,3,  "DITJAAR PEN",  "$ditjaar_pen_event_title ($ditjaar_pos_part_rol $ditevent_leid_welkkamp)");
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
        $ditjaar_part_eventid           = $array_partditjaar['part_event_id']               ?? NULL;
        $ditjaar_part_role_id           = $array_partditjaar['role_id']                     ?? NULL;
        $ditjaar_part_status_id         = $array_partditjaar['status_id']                   ?? NULL;
        $ditjaar_part_status_name       = $array_partditjaar['status_name']                 ?? NULL;
        $ditjaar_part_register_date     = $array_partditjaar['register_date']               ?? NULL;            
        $ditjaar_part_event_id          = $array_partditjaar['event_id']                    ?? NULL;
        $ditjaar_part_event_title       = $array_partditjaar['event_title']                 ?? NULL;
        $ditjaar_part_event_type_id     = $array_partditjaar['event_type_id']               ?? NULL;
        $ditjaar_part_event_type_label  = $array_partditjaar['event_type_label']            ?? NULL;

        $ditjaar_part_kampjaar          = $array_partditjaar['part_kampjaar']               ?? NULL;
        $ditjaar_part_kampnaam          = $array_partditjaar['part_kampnaam']               ?? NULL;
        $ditjaar_part_kampkort          = $array_partditjaar['part_kampkort']               ?? NULL;

        $ditjaar_part_kamptype_id       = $array_partditjaar['part_kamptype_id']            ?? NULL;
        $ditjaar_part_functie           = $array_partditjaar['part_functie']                ?? NULL;
        $ditjaar_part_rol               = $array_partditjaar['part_rol']                    ?? NULL;
        $ditjaar_leid_welkkamp          = $array_partditjaar['part_leid_kamp']              ?? NULL;
        $ditjaar_leid_functie           = $array_partditjaar['part_leid_functie']           ?? NULL;

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

        $ditjaar_wachtlijst_erop        = $array_partditjaar['part_wachtlijst_erop']        ?? NULL;
        $ditjaar_wachtlijst_eraf        = $array_partditjaar['part_wachtlijst_eraf']        ?? NULL;
        $ditjaar_criteriacheck_start    = $array_partditjaar['part_criteriacheck_start']    ?? NULL;
        $ditjaar_criteriacheck_einde    = $array_partditjaar['part_criteriacheck_einde']    ?? NULL;

        $ditjaar_criteria_indicatie     = $array_partditjaar['part_criteria_indicatie']     ?? NULL;
        $ditjaar_criteria_oordeel       = $array_partditjaar['part_criteria_oordeel']       ?? NULL;

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
            $ditjaar_part_vogverzocht   = $array_partditjaar['part_vogverzocht']            ?? NULL;
            $ditjaar_part_vogingediend  = $array_partditjaar['part_vogingediend']           ?? NULL;
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

    }

    watchdog('civicrm_timing', core_microtimer("EINDE 1.X get variables"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### CORE 2.X CHECK CRITERIA & STATUS DIT JAAR EN DIT EVENT");
    wachthond($extdebug,3, "########################################################################");

    watchdog('civicrm_timing', core_microtimer("START 2.X bepaal basisinfo"), NULL, WATCHDOG_DEBUG);

    if (in_array($groupID, $profilecvmax)) { // PROFILE CONT & PART (BASIC)

        ###########################################################################################
        ### BEPAAL LAST & NEXT EVENT DATES");
        ###########################################################################################

        $today_nextkamp_lastnext  = find_lastnext($today_datetime); 
        wachthond($extdebug,3, 'today_nextkamp_lastnext',               $today_nextkamp_lastnext);

        $today_nextkamp_start_date  =   $today_nextkamp_lastnext['next_start_date'];
        wachthond($extdebug,3, 'today_nextkamp_start_date',             $today_nextkamp_start_date);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### CORE 2.1 RETRIEVE CURRENT AGE AND AGE AT EVENT",     "$displayname");
        wachthond($extdebug,2, "########################################################################");

        $leeftijd_vantoday_decimalen = NULL;
        $leeftijd_vantoday_rondjaren = NULL;

        if ($birth_date AND $today_datetime) {

            $leeftijd_vantoday = leeftijd_civicrm_diff('vandaag', $birth_date, $today_datetime);
            wachthond($extdebug,3, 'leeftijd_vantoday',       $leeftijd_vantoday);
            $leeftijd_vantoday_decimalen  = $leeftijd_vantoday['leeftijd_decimalen'] ?? NULL;
            $leeftijd_vantoday_rondjaren  = $leeftijd_vantoday['leeftijd_rondjaren'] ?? NULL;
            wachthond($extdebug,4, 'leeftijd_vantoday_decimalen', $leeftijd_vantoday_decimalen);
        }

        $leeftijd_ditevent_decimalen = NULL;
        $leeftijd_ditevent_rondjaren = NULL;

        if ($birth_date AND $ditevent_event_start) {

            $leeftijd_ditevent = leeftijd_civicrm_diff('ditevent',  $birth_date, $ditevent_event_start);
            wachthond($extdebug,3, 'leeftijd_ditevent',       $leeftijd_ditevent);
            $leeftijd_ditevent_decimalen  = $leeftijd_ditevent['leeftijd_decimalen'] ?? NULL;
            $leeftijd_ditevent_rondjaren  = $leeftijd_ditevent['leeftijd_rondjaren'] ?? NULL;
            wachthond($extdebug,4, 'leeftijd_ditevent_decimalen',   $leeftijd_ditevent_decimalen);
        }

        $leeftijd_nextkamp_decimalen = NULL;
        $leeftijd_nextkamp_rondjaren = NULL;
        $leeftijd_nextkamp_rondmaand = NULL;

        if ($birth_date AND $today_nextkamp_start_date) {

            $leeftijd_nextkamp = leeftijd_civicrm_diff('nextkamp',  $birth_date, $today_nextkamp_start_date);
            wachthond($extdebug,3, 'leeftijd_nextkamp',       $leeftijd_nextkamp);
            $leeftijd_nextkamp_decimalen = $leeftijd_nextkamp['leeftijd_decimalen'] ?? NULL;
            $leeftijd_nextkamp_rondjaren = $leeftijd_nextkamp['leeftijd_rondjaren'] ?? NULL;
            $leeftijd_nextkamp_rondmaand = $leeftijd_nextkamp['leeftijd_rondmaand'] ?? NULL;
            wachthond($extdebug,4, 'leeftijd_nextkamp_decimalen',   $leeftijd_nextkamp_decimalen);
            wachthond($extdebug,4, 'leeftijd_nextkamp_rondjaren',   $leeftijd_nextkamp_rondjaren);
            wachthond($extdebug,4, 'leeftijd_nextkamp_rondmaand',   $leeftijd_nextkamp_rondmaand);
        }
    }

    if (in_array($groupID, $profilepartdeel)) { // PROFILE PART DEEL)

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### CORE 2.2 BEOORDEEL LEEFTIJDSCRITERIA DIT EVENT",         $displayname);
        wachthond($extdebug,2, "########################################################################");

        $array_criteria_ditevent = leeftijd_civicrm_criteria($array_partditevent, $leeftijd_ditevent_decimalen);

        wachthond($extdebug,4, "########################################################################");   
        wachthond($extdebug,1, "RECEIVE leeftijd_criteria_ditevent",             $array_criteria_ditevent);
        wachthond($extdebug,2, "########################################################################");   

        $new_ditevent_criteria_leeftijd   = $array_criteria_ditevent['criteria_leeftijd']    ?? NULL;
        $new_ditevent_criteria_school     = $array_criteria_ditevent['criteria_school']      ?? NULL;
        $new_ditevent_criteria_indicatie  = $array_criteria_ditevent['criteria_indicatie']   ?? NULL;
        $new_ditevent_criteria_oordeel    = $array_criteria_ditevent['criteria_oordeel']     ?? NULL;

        wachthond($extdebug,3, "INDICATIE/OORDEEL CRITERIA OBV $ditevent_event_kampkort $ditevent_kampjaar","NU");

        wachthond($extdebug,4, 'ditevent_leeftijd_decimalen',         $leeftijd_ditevent_decimalen);
        wachthond($extdebug,4, 'ditevent_part_groepklas',             $ditevent_part_groepklas);
        wachthond($extdebug,3, 'org_ditevent_criteria_indicatie',     $ditevent_criteria_indicatie);
        wachthond($extdebug,3, 'org_ditevent_criteria_oordeel',       $ditevent_criteria_oordeel);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,3, "INDICATIE/OORDEEL CRITERIA OBV $ditevent_event_kampkort $ditevent_kampjaar","NEW");

        wachthond($extdebug,4, 'new_ditevent_criteria_leeftijd',      $new_ditevent_criteria_leeftijd);
        wachthond($extdebug,4, 'new_ditevent_criteria_school',        $new_ditevent_criteria_school);
        wachthond($extdebug,2, 'new_ditevent_criteria_indicatie',     $new_ditevent_criteria_indicatie);
        wachthond($extdebug,2, 'new_ditevent_criteria_oordeel',       $new_ditevent_criteria_oordeel);
    }

    if (in_array($groupID, $profilepart)) { // PROFILE PART)

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### CORE 2.3 PAS PART STATUS AAN OBV CRITERIA",            "$displayname");
        wachthond($extdebug,2, "########################################################################");

        $array_status_ditevent = leeftijd_civicrm_status($array_partditevent, $array_criteria_ditevent);

        if ($ditevent_part_rol == 'deelnemer') {
            $new_ditevent_criteriacheck_start = $array_status_ditevent['criteriacheck_start'];
            $new_ditevent_criteriacheck_einde = $array_status_ditevent['criteriacheck_einde'];
            $new_ditevent_wachtlijst_erop     = $array_status_ditevent['wachtlijst_erop'];
            $new_ditevent_wachtlijst_eraf     = $array_status_ditevent['wachtlijst_eraf'];  
        }

        $new_ditevent_part_status_id          = $array_status_ditevent['ditevent_part_status_id'];
        $new_ditevent_part_status_name        = $array_status_ditevent['ditevent_part_status_name'];
        $new_ditevent_deelnamestatus          = $array_status_ditevent['ditevent_deelnamestatus'];

        wachthond($extdebug,4, "########################################################################");   
        wachthond($extdebug,3, "RECEIVE leeftijd_status_ditevent",                 $array_status_ditevent);
        wachthond($extdebug,2, "########################################################################");

        if ($ditevent_part_rol == 'deelnemer') {
            wachthond($extdebug,3, 'new_ditevent_criteriacheck_start',  $new_ditevent_criteriacheck_start);
            wachthond($extdebug,3, 'new_ditevent_criteriacheck_einde',  $new_ditevent_criteriacheck_einde);
            wachthond($extdebug,3, 'new_ditevent_wachtlijst_erop',      $new_ditevent_wachtlijst_erop);
            wachthond($extdebug,3, 'new_ditevent_wachtlijst_eraf',      $new_ditevent_wachtlijst_eraf);
        }

        wachthond($extdebug,3, 'new_ditevent_part_status_id',           $new_ditevent_part_status_id);
        wachthond($extdebug,3, 'new_ditevent_part_status_name',         $new_ditevent_part_status_name);
        wachthond($extdebug,3, 'new_ditevent_deelnamestatus',           $new_ditevent_deelnamestatus);
    }

    if ($ditjaar_prim_partid == $ditevent_part_id) {

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### CORE 2.4 INDIEN PRIMEVENT == DITEVENT > HERBRUIK CRITERIA DITEVENT");
        wachthond($extdebug,2, "########################################################################");

        $array_criteria_ditjaar                 = $array_criteria_ditevent;
        $array_status_ditjaar                   = $array_status_ditevent;

        if ($ditjaar_part_rol == 'deelnemer') {
            $new_ditjaar_criteria_leeftijd      = $new_ditevent_criteria_leeftijd;
            $new_ditjaar_criteria_school        = $new_ditevent_criteria_school;
            $new_ditjaar_criteria_indicatie     = $new_ditevent_criteria_indicatie;
            $new_ditjaar_criteria_oordeel       = $new_ditevent_criteria_oordeel;

            $new_ditjaar_criteriacheck_start    = $new_ditevent_criteriacheck_start;
            $new_ditjaar_criteriacheck_einde    = $new_ditevent_criteriacheck_einde;
            $new_ditjaar_wachtlijst_erop        = $new_ditevent_wachtlijst_erop;
            $new_ditjaar_wachtlijst_eraf        = $new_ditevent_wachtlijst_eraf;
        }

        $new_ditjaar_part_status_id             = $new_ditevent_part_status_id;
        $new_ditjaar_part_status_name           = $new_ditevent_part_status_name;
        $new_ditjaar_deelnamestatus             = $new_ditevent_deelnamestatus;

        if ($ditjaar_part_rol == 'deelnemer') {

            wachthond($extdebug,2, 'new_ditjaar_criteria_leeftijd',     $new_ditjaar_criteria_leeftijd);
            wachthond($extdebug,2, 'new_ditjaar_criteria_school',       $new_ditjaar_criteria_school);
            wachthond($extdebug,2, 'new_ditjaar_criteria_indicatie',    $new_ditjaar_criteria_indicatie);
            wachthond($extdebug,2, 'new_ditjaar_criteria_oordeel',      $new_ditjaar_criteria_oordeel);

            wachthond($extdebug,3, 'new_ditjaar_criteriacheck_start',   $new_ditjaar_criteriacheck_start);
            wachthond($extdebug,3, 'new_ditjaar_criteriacheck_einde',   $new_ditjaar_criteriacheck_einde);
            wachthond($extdebug,3, 'new_ditjaar_wachtlijst_erop',       $new_ditjaar_wachtlijst_erop);
            wachthond($extdebug,3, 'new_ditjaar_wachtlijst_eraf',       $new_ditjaar_wachtlijst_eraf);
        }

            wachthond($extdebug,3, 'new_ditjaar_part_status_id',        $new_ditjaar_part_status_id);
            wachthond($extdebug,3, 'new_ditjaar_part_status_name',      $new_ditjaar_part_status_name);
            wachthond($extdebug,3, 'new_ditjaar_deelnamestatus',        $new_ditjaar_deelnamestatus);        

    } else {

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### CORE 2.5 BEOORDEEL LEEFTIJDSCRITERIA (PRIM DITJAAR)",    $displayname);
        wachthond($extdebug,2, "########################################################################");

        $leeftijd_ditjaar           = leeftijd_civicrm_diff('ditevent', $birth_date, $ditjaar_event_start);
        wachthond($extdebug,3, 'RECEIVE leeftijd_ditjaar',             $leeftijd_ditjaar);

        $leeftijd_ditjaar_decimalen  = $leeftijd_ditjaar['leeftijd_decimalen'] ?? NULL;
        $leeftijd_ditjaar_rondjaren  = $leeftijd_ditjaar['leeftijd_rondjaren'] ?? NULL;
        wachthond($extdebug,2, 'leeftijd_ditjaar_decimalen',           $leeftijd_ditjaar_decimalen);

        if ($ditjaar_part_rol == 'deelnemer') {

            wachthond($extdebug,4, 'array_partditjaar',                 $array_partditjaar);

            watchdog('civicrm_timing', core_microtimer("START leeftijd_criteria"), NULL, WATCHDOG_DEBUG);
            $array_criteria_ditjaar = leeftijd_civicrm_criteria($array_partditjaar, $leeftijd_ditjaar_decimalen);
            watchdog('civicrm_timing', core_microtimer("EINDE leeftijd_criteria"), NULL, WATCHDOG_DEBUG);

            wachthond($extdebug,2, "########################################################################");   
            wachthond($extdebug,1, "RECEIVE leeftijd_criteria_ditjaar",               $array_criteria_ditjaar);
            wachthond($extdebug,2, "########################################################################");   

            $new_ditjaar_criteria_leeftijd  = $array_criteria_ditjaar['criteria_leeftijd']      ?? NULL;
            $new_ditjaar_criteria_school    = $array_criteria_ditjaar['criteria_school']        ?? NULL;
            $new_ditjaar_criteria_indicatie = $array_criteria_ditjaar['criteria_indicatie']     ?? NULL;
            $new_ditjaar_criteria_oordeel   = $array_criteria_ditjaar['criteria_oordeel']       ?? NULL;

            wachthond($extdebug,3, "INDICATIE CRITERIA OBV $ditjaar_event_kampkort $ditjaar_event_kampjaar");
            wachthond($extdebug,3, 'leeftijd_ditjaar_decimalen',        $leeftijd_ditjaar_decimalen);
            wachthond($extdebug,3, 'ditjaar_part_groepklas',            $ditjaar_part_groepklas);
            wachthond($extdebug,2, 'new_ditjaar_criteria_leeftijd',     $new_ditjaar_criteria_leeftijd);
            wachthond($extdebug,2, 'new_ditjaar_criteria_school',       $new_ditjaar_criteria_school);
            wachthond($extdebug,3, 'org_ditjaar_criteria_indicatie',    $ditjaar_criteria_indicatie);
            wachthond($extdebug,3, 'org_ditjaar_criteria_oordeel',      $ditjaar_criteria_oordeel);
            wachthond($extdebug,2, 'new_ditjaar_criteria_indicatie',    $new_ditjaar_criteria_indicatie);
            wachthond($extdebug,2, 'new_ditjaar_criteria_oordeel',      $new_ditjaar_criteria_oordeel);
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### CORE 2.6 PAS PART STATUS (PRIM) AAN OBV CRITERIA",       $displayname);
        wachthond($extdebug,2, "########################################################################");

        watchdog('civicrm_timing', core_microtimer("START leeftijd_status"), NULL, WATCHDOG_DEBUG);
        $array_status_ditjaar = leeftijd_civicrm_status($array_partditjaar, $array_criteria_ditjaar);
        watchdog('civicrm_timing', core_microtimer("EINDE leeftijd_status"), NULL, WATCHDOG_DEBUG);

        if ($ditjaar_part_rol == 'deelnemer') {
            $new_ditjaar_criteriacheck_start = $array_status_ditjaar['criteriacheck_start'];
            $new_ditjaar_criteriacheck_einde = $array_status_ditjaar['criteriacheck_einde'];
            $new_ditjaar_wachtlijst_erop     = $array_status_ditjaar['wachtlijst_erop'];
            $new_ditjaar_wachtlijst_eraf     = $array_status_ditjaar['wachtlijst_eraf'];
        }
            $new_ditjaar_part_status_id      = $array_status_ditjaar['ditevent_part_status_id'];
            $new_ditjaar_part_status_name    = $array_status_ditjaar['ditevent_part_status_name'];
            $new_ditjaar_deelnamestatus      = $array_status_ditjaar['ditevent_deelnamestatus'];

        wachthond($extdebug,2, "########################################################################");   
        wachthond($extdebug,3, "RECEIVE leeftijd_status_ditjaar",                   $array_status_ditjaar);
        wachthond($extdebug,2, "########################################################################");

        if ($ditjaar_part_rol == 'deelnemer') {

            wachthond($extdebug,3, 'new_ditjaar_criteriacheck_start',   $new_ditjaar_criteriacheck_start);
            wachthond($extdebug,3, 'new_ditjaar_criteriacheck_einde',   $new_ditjaar_criteriacheck_einde);
            wachthond($extdebug,3, 'new_ditjaar_wachtlijst_erop',       $new_ditjaar_wachtlijst_erop);
            wachthond($extdebug,3, 'new_ditjaar_wachtlijst_eraf',       $new_ditjaar_wachtlijst_eraf);
        }

            wachthond($extdebug,3, 'new_ditjaar_part_status_id',        $new_ditjaar_part_status_id);
            wachthond($extdebug,3, 'new_ditjaar_part_status_name',      $new_ditjaar_part_status_name);
            wachthond($extdebug,3, 'new_ditjaar_deelnamestatus',        $new_ditjaar_deelnamestatus);

        if ($ditjaar_part_rol == 'deelnemer') {

            wachthond($extdebug,2, 'new_ditjaar_criteria_leeftijd',     $new_ditjaar_criteria_leeftijd);
            wachthond($extdebug,2, 'new_ditjaar_criteria_school',       $new_ditjaar_criteria_school);
            wachthond($extdebug,2, 'new_ditjaar_criteria_indicatie',    $new_ditjaar_criteria_indicatie);
            wachthond($extdebug,2, 'new_ditjaar_criteria_oordeel',      $new_ditjaar_criteria_oordeel);

            wachthond($extdebug,3, 'new_ditevent_criteria_indicatie',   $new_ditevent_criteria_indicatie);
            wachthond($extdebug,3, 'new_ditevent_criteria_oordeel',     $new_ditevent_criteria_oordeel);
            wachthond($extdebug,3, 'new_ditjaar_criteria_indicatie',    $new_ditjaar_criteria_indicatie);
            wachthond($extdebug,3, 'new_ditjaar_criteria_oordeel',      $new_ditjaar_criteria_oordeel);
        }
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### CORE 2.7 BEPAAL DE DEELNAMESTATUS DIT JAAR EN DIT EVENT");
    wachthond($extdebug,3, "########################################################################");

    wachthond($extdebug,3, "array_partditevent",      $array_partditevent);
    wachthond($extdebug,3, "array_allpart_eventjaar", $array_allpart_eventjaar);
    wachthond($extdebug,3, "array_status_ditevent",   $array_status_ditevent);

    wachthond($extdebug,1, core_microtimer("Start raadplegen mee_configure ditevent"));
    // 1. BEREKEN STATUS VOOR HET EVENT
    $ditevent_array = mee_civicrm_configure($contact_id,                $array_partditevent,
                                            $array_allpart_eventjaar,   $array_status_ditevent, $array_criteria_ditevent);  // jaar event
    wachthond($extdebug,1, core_microtimer("Einde raadplegen mee_configure ditevent"));

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

    wachthond($extdebug,1, 'array_partditjaar',         $array_partditjaar);
    wachthond($extdebug,1, 'array_allpart_ditjaar',     $array_allpart_ditjaar);
    wachthond($extdebug,1, 'array_status_ditjaar',      $array_status_ditjaar);
    wachthond($extdebug,1, 'array_criteria_ditjaar',    $array_criteria_ditjaar);

    wachthond($extdebug,1, core_microtimer("Start raadplegen mee_configure ditjaar"));
    // 2. BEREKEN STATUS VOOR HET HUIDIGE JAAR (Los van het event)
    $ditjaar_array = mee_civicrm_configure($contact_id,             $array_allpart_ditjaar, 
                                           $array_partditjaar,      $array_status_ditjaar,  $array_criteria_ditjaar);  // huidig jaar
    wachthond($extdebug,1, core_microtimer("Einde raadplegen mee_configure ditjaar"));

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
                  "[EID $ditjaar_pos_deel_eventid\tPID $ditjaar_pos_deel_part_id\t$ditjaar_pos_deel_kampkort]");
        wachthond($extdebug,1,  "DITJAAR      $ditjaar_part_kampjaar GAAT $displayname $ditjaarleidtxt MEE ALS LEID",
                  "[EID $ditjaar_pos_leid_eventid\tPID $ditjaar_pos_leid_part_id\t$ditjaar_pos_leid_kampkort]");
        wachthond($extdebug,2, "########################################################################");
    } else {
        wachthond($extdebug,1,  "DITJAAR      $ditjaar_part_kampjaar GAAT $displayname NIET MEE OP KAMP");
    }
    if ($ditevent_part_id >= 1) {
        wachthond($extdebug,1,  "DITEVENT     $ditevent_kampjaar GAAT $displayname $diteventdeeltxt MEE ALS DEEL",
                  "[EID $ditevent_part_eventid\tPID $ditevent_part_id\t$ditevent_event_kampkort]");
        wachthond($extdebug,1,  "DITEVENT     $ditevent_kampjaar GAAT $displayname $diteventleidtxt MEE ALS LEID",
                  "[EID $ditevent_part_eventid\tPID $ditevent_part_id\t$ditevent_event_kampkort]");
        wachthond($extdebug,2, "########################################################################");
    } else {
        wachthond($extdebug,1,  "DITEVENT     [ER WORDT GEEN EVENT BEWERKT]");
    }
/*
    if ($eventjaar_pos_part_id >= 1) {
        wachthond($extdebug,1,  "EVENTJAAR    $ditevent_kampjaar GAAT $displayname $eventjaardeeltxt MEE ALS DEEL",
                  "[EID $eventjaar_pos_deel_event_id\tPID $eventjaar_pos_deel_part_id\t$eventjaar_pos_deel_kampkort]");
        wachthond($extdebug,1,  "EVENTJAAR    $ditevent_kampjaar GAAT $displayname $eventjaarleidtxt MEE ALS LEID",
                  "[EID $eventjaar_pos_leid_event_id\tPID $eventjaar_pos_deel_part_id\t$eventjaar_pos_leid_kampkort]");
        wachthond($extdebug,3,  "########################################################################");
    } else {
        wachthond($extdebug,1,  "EVENTJAAR    [ER WORDT GEEN EVENT BEWERKT]");
    }
*/
    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,1, "### CORE 2.X EINDE BEPAAL BASISINFO VOOR DIT CONTACT / DEZE PARTICIPANT", "$displayname");
    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,4, 'new_ditevent_criteria_indicatie',       $new_ditevent_criteria_indicatie);
    wachthond($extdebug,4, 'new_ditevent_criteria_oordeel',         $new_ditevent_criteria_oordeel);
    wachthond($extdebug,4, 'new_ditjaar_criteria_indicatie',        $new_ditjaar_criteria_indicatie);
    wachthond($extdebug,4, 'new_ditjaar_criteria_oordeel',          $new_ditjaar_criteria_oordeel);

    } else {

        wachthond($extdebug,4, "########################################################################");
        wachthond($extdebug,1, "### CORE 2.X SKIPPED BEPAAL BASISINFO VOOR DIT CONTACT / DEZE PARTICIPANT", "[groupID: $groupID] [op: $op] [entityID: $entityID]");
        wachthond($extdebug,4, "########################################################################");

        wachthond($extdebug,4, 'new_ditevent_criteria_indicatie',   $new_ditevent_criteria_indicatie);
        wachthond($extdebug,4, 'new_ditevent_criteria_oordeel',     $new_ditevent_criteria_oordeel);
        wachthond($extdebug,4, 'new_ditjaar_criteria_indicatie',    $new_ditjaar_criteria_indicatie);
        wachthond($extdebug,4, 'new_ditjaar_criteria_oordeel',      $new_ditjaar_criteria_oordeel);
    } 

    watchdog('civicrm_timing', core_microtimer("EINDE 2.X bepaal basisinfo"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE 3.X START CORRECT CV",           "[groupID: $groupID] [op: $op]");
    wachthond($extdebug,1, "########################################################################");

    watchdog('civicrm_timing', core_microtimer("START correct CV"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,3, 'contact_id',            $contact_id);
    wachthond($extdebug,3, 'array_contditjaar',     $array_contditjaar);
    wachthond($extdebug,4, 'ditjaar_array',         $ditjaar_array);

    if ($contact_id AND is_array($array_contditjaar)) {

        wachthond($extdebug,3,      'CORRECT CV',   "EXECUTE");

        watchdog('civicrm_timing', core_microtimer("START bepaal CV"), NULL, WATCHDOG_DEBUG);
        $array_cv = cv_civicrm_configure($contact_id, $array_contditjaar, $ditjaar_array);
        watchdog('civicrm_timing', core_microtimer("EINDE bepaal CV"), NULL, WATCHDOG_DEBUG);
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

    watchdog('civicrm_timing', core_microtimer("EINDE correct CV"), NULL, WATCHDOG_DEBUG);

    ##########################################################################################
    # 4.X START CORRECT MISCELANEOUS VALUES
    ##########################################################################################

    if (in_array($groupID, $profilecv)) {

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### CORE 4.X START CORRECT MISCELANEOUS VALUES", "[groupID: $groupID] [op: $op]");
        wachthond($extdebug,1, "########################################################################");

        watchdog('civicrm_timing', core_microtimer("START segment 4.X CORRECT MISCELANEOUS VALUES"), NULL, WATCHDOG_DEBUG);
    }

    ##########################################################################################
    if ($extchk == 1 AND in_array($groupID, $profilecv)) {    // PROFILE CONT + PART (BASIC)
    ##########################################################################################

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.1 CONSTRUCT (DRUPAL) USERNAME","[groupID: $groupID] [op: $op]");
    wachthond($extdebug,2, "########################################################################");

    wachthond($extdebug,3, 'contact_id',        $contact_id);
    wachthond($extdebug,3, 'first_name',        $first_name);
    wachthond($extdebug,3, 'middle_name',       $middle_name);
    wachthond($extdebug,3, 'last_name',         $last_name);
    wachthond($extdebug,3, 'nick_name',         $nick_name);
    wachthond($extdebug,2, 'displayname',       $displayname);

    watchdog('civicrm_timing', core_microtimer("START configure drupal username"), NULL, WATCHDOG_DEBUG);
    $array_username             = drupal_civicrm_username($contact_id, $first_name, $middle_name, $last_name, $displayname, $nick_name);
    watchdog('civicrm_timing', core_microtimer("EINDE configure drupal username"), NULL, WATCHDOG_DEBUG);

    $first_name                 = $array_username['first_name'];
    $middle_name                = $array_username['middle_name'];
    $last_name                  = $array_username['last_name'];
    $nick_name                  = $array_username['nick_name'];

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

    watchdog('civicrm_timing', core_microtimer("START configure account"), NULL, WATCHDOG_DEBUG);
    $account_array = account_civicrm_configure($contact_id);
    wachthond($extdebug,3, "account_array",             $account_array);
    watchdog('civicrm_timing', core_microtimer("EINDE configure account"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.3 CONFIGURE ACL GROUP MEMBERSCHIP AND PERMISSIONS", "[$birth_date]");
    wachthond($extdebug,2, "########################################################################");

    wachthond($extdebug,4, "array_contditjaar",         $array_contditjaar);
    wachthond($extdebug,4, "ditjaar_array",             $ditjaar_array);
    wachthond($extdebug,4, "array_allpart_ditjaar",     $array_allpart_ditjaar);
    wachthond($extdebug,4, "valid_drupalid",            $valid_drupalid);
    wachthond($extdebug,4, "ditjaar_rollen_array",      $ditjaar_rollen_array);

    watchdog('civicrm_timing', core_microtimer("START configure ACL"), NULL, WATCHDOG_DEBUG);
    $aclresult = acl_civicrm_configure($contact_id, $array_contditjaar, $ditjaar_array, $array_allpart_ditjaar, $valid_drupalid, $ditjaar_rollen_array);
    watchdog('civicrm_timing', core_microtimer("EINDE configure ACL"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.4 GET EMAILADRESSS TBV NOTIFICATIES");
    wachthond($extdebug,2, "########################################################################");

    ############################ BIJ 6.7 STELEN WE DE NOTIF_EMAILS IN N.A.V. DE VOORKEUREN ###########

    wachthond($extdebug,4, 'array_contditjaar',         $array_contditjaar);
    wachthond($extdebug,4, 'ditjaar_array',             $ditjaar_array);
    wachthond($extdebug,4, 'array_partditjaar',         $array_partditjaar);

    watchdog('civicrm_timing', core_microtimer("START configure EMAIL"), NULL, WATCHDOG_DEBUG);
    $array_email        = email_civicrm_configure($array_contditjaar, $ditjaar_array, $array_partditjaar, $datum_belangstelling);
    watchdog('civicrm_timing', core_microtimer("EINDE configure EMAIL"), NULL, WATCHDOG_DEBUG);

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

    watchdog('civicrm_timing', core_microtimer("START configure drupal account"), NULL, WATCHDOG_DEBUG);
    drupal_civicrm_configure($contact_id, $displayname, $user_mail, $ditjaar_array, $array_allpart_ditjaar);
    watchdog('civicrm_timing', core_microtimer("EINDE configure drupal account"), NULL, WATCHDOG_DEBUG);

    // M61: EXTRA HIER (NOG EEN KEER) EMAIL / DRUPAL / EMAIL
    watchdog('civicrm_timing', core_microtimer("START configure EMAIL 2"), NULL, WATCHDOG_DEBUG);
    $array_email        = email_civicrm_configure($array_contditjaar, $ditjaar_array, $array_partditjaar, $datum_belangstelling);
    watchdog('civicrm_timing', core_microtimer("EINDE configure EMAIL 2"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.6 DEFINE POSTAL & EMAIL GREETING", "[groupID: $groupID] [op: $op]");
    wachthond($extdebug,2, "########################################################################");

    watchdog('civicrm_timing', core_microtimer("START configure GREETING"), NULL, WATCHDOG_DEBUG);
    $array_greeting = email_civicrm_greeting($array_contditjaar, $ditjaar_array, $array_partditjaar);
    watchdog('civicrm_timing', core_microtimer("START configure GREETING"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,4, 'RECEIVE array_cv', $array_cv);

    $email_greeting_id              = $array_greeting['email_greeting_id']                  ?? NULL;
    $communication_style_id         = $array_greeting['communication_style_id']             ?? NULL;

    wachthond($extdebug,3, 'email_greeting_id',         $email_greeting_id);
    wachthond($extdebug,3, 'communication_style_id',    $communication_style_id);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.8 CONFIGURE VAKANTIEREGIO SCHOOLVAKANTIE", "[$displayname]");
    wachthond($extdebug,2, "########################################################################");

    watchdog('civicrm_timing', core_microtimer("START configure REGIO"), NULL, WATCHDOG_DEBUG);
    $vakantieregio = werving_civicrm_vakantieregio($contact_id);
    watchdog('civicrm_timing', core_microtimer("EINDE configure REGIO"), NULL, WATCHDOG_DEBUG);
    wachthond($extdebug,1, "vakantieregio",       $vakantieregio);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.9 A RETREIVE RELATIONSHIPS OF THIS CONTACT", "[groupID: $groupID] [op: $op]");
    wachthond($extdebug,2, "########################################################################");

    watchdog('civicrm_timing', core_microtimer("START configure REL/GAVE"), NULL, WATCHDOG_DEBUG);

    if (in_array($groupID, $profilepart)) {     // PROFILE PART

        if (empty($related_gavecontact_relid)) {

            wachthond($extdebug,1, "### CORE 4.8 0 A RETRIEVE RELATED GAVECONTACT ###", "[groupID: $groupID] [op: $op]");

            $params_get_rel_gavecontact = [
              'checkPermissions' => FALSE,
              'debug' => $apidebug,
                'select' => [
                'row_count', 'contact_id_a', 'contact_id_b', 'is_active', 'start_date', 'end_date', 'id',
                ],
                'where' => [
                    ['contact_id_a',    '=',  $contact_id],
                    ['relationship_type_id','=',  20],
    #               ['start_date',        '>=', $ditevent_fiscalyear_start],
    #               ['end_date',          '<=', $eventkamp_fiscalyear_einde],
                    ['is_active',         '=',  TRUE],
                ],
            ];

            wachthond($extdebug,7, 'params_get_rel_gavecontact',        $params_get_rel_gavecontact);
            $result_get_rel_gavecontact = civicrm_api4('Relationship', 'get',   $params_get_rel_gavecontact);
            wachthond($extdebug,9, 'result_get_rel_gavecontact',        $result_get_rel_gavecontact);

            $result_get_rel_gavecontact_count = $result_get_rel_gavecontact->countMatched();
            if ($result_get_rel_gavecontact_count == 1) {
                $related_gavecontact_id     = $result_get_rel_gavecontact[0]['contact_id_b']  ?? NULL;
                $related_gavecontact_relid    = $result_get_rel_gavecontact[0]['id']      ?? NULL;
                wachthond($extdebug,1,  "PRIMA: =1 RELATED ACTIEVE GAVECONTACT GEVONDEN",
                                        "result_get_rel_gavecontact_count: $result_get_rel_gavecontact_count");
            } elseif ($result_get_rel_gavecontact_count >= 1) {
                wachthond($extdebug,1,  "ERROR: >1 RELATED ACTIEVE GAVECONTACT GEVONDEN",
                                        "result_get_rel_gavecontact_count: $result_get_rel_gavecontact_count");
            } else {
                $related_gavecontact_id     = NULL;
                $related_gavecontact_relid    = NULL;

                wachthond($extdebug,1,  "ERROR: =0 RELATED ACTIEVE GAVECONTACT GEVONDEN", 
                                        "result_get_rel_gavecontact_count: $result_get_rel_gavecontact_count");
            }
            wachthond($extdebug,3, 'related_gavecontact_id',        $related_gavecontact_id);
            wachthond($extdebug,3, 'related_gavecontact_relid',     $related_gavecontact_relid);
        }
    }

    ##########################################################################################
    // GET PHONE VAN GAVECONTACT
    ##########################################################################################

    $params_gave_contact = [
      'checkPermissions' => FALSE,
      'debug'  => $apidebug,        
      'select' => [
        'display_name', 'first_name', 'image_URL',
      ],
      'where' => [
          ['id',        'IN', [$related_gavecontact_id]],
      ],
    ];
    $params_gave_phone = [
      'checkPermissions' => FALSE,
      'debug'  => $apidebug,        
      'select' => [
        'phone',
      ],
      'where' => [
        ['contact_id',      'IN', [$related_gavecontact_id]],
        ['location_type_id',  '=', 1],
        ['phone_type_id',     '=', 2],
      ],
    ];
    $params_gave_email = [
      'checkPermissions' => FALSE,
      'debug'  => $apidebug,
      'select' => [
        'email',
      ],
      'where' => [
        ['contact_id',      'IN', [$related_gavecontact_id]],
        ['location_type_id',  '=', 1],
      ],
    ];
    wachthond($extdebug,7, 'params_gave_contact',           $params_gave_contact);
    wachthond($extdebug,7, 'params_gave_phone',             $params_gave_phone);
    wachthond($extdebug,7, 'params_gave_email',             $params_gave_email);
    $result_gave_contact  = civicrm_api4('Contact','get',   $params_gave_contact);
    $result_gave_phone    = civicrm_api4('Phone',  'get',   $params_gave_phone);
    $result_gave_email    = civicrm_api4('Email',  'get',   $params_gave_email);
    wachthond($extdebug,9, 'result_gave_contact',           $result_gave_contact);
    wachthond($extdebug,9, 'result_gave_phone',             $result_gave_phone);
    wachthond($extdebug,9, 'result_gave_email',             $result_gave_email);

    if (isset($result_gave_contact))  {
      $gave_contact_naam    = $result_gave_contact[0]['display_name']     ?? NULL;
      $gave_contact_foto    = $result_gave_contact[0]['image_URL']      ?? NULL;
      wachthond($extdebug,2, 'gave_contact_naam',     $gave_contact_naam);
      wachthond($extdebug,2, 'gave_contact_foto',     $gave_contact_foto);
    } else {
      $gave_contact_naam    = "";
      $gave_contact_foto    = "";
    }

    if (isset($result_gave_phone))  {
      $gave_contact_phone   = $result_gave_phone[0]['phone']    ?? NULL;
      $new_phone_gave_phone   = $gave_contact_phone;
      wachthond($extdebug,2, 'gave_contact_phone',    $gave_contact_phone);
    } else {
      $gave_contact_phone   = "";
    }
    if (isset($result_gave_email))  {
      $gave_contact_email   = $result_gave_email[0]['email']    ?? NULL;
      $new_email_gave_email   = $gave_contact_email;
      wachthond($extdebug,2, 'gave_contact_email',    $gave_contact_email);
    } else {
      $gave_contact_email   = "";
    }
/*
    $params_gave_email = [
      'checkPermissions' => FALSE,
      'debug'  => $apidebug,        
        'select' => [
        'email',
        ],
        'where' => [
          ['contact_id',      'IN', [$related_gavecontact_id]],
          ['location_type_id',  '=', 1],
        ],
    ];
    wachthond($extdebug,7, 'params_gave_contact',     $params_gave_contact);
    wachthond($extdebug,7, 'params_gave_email  ',       $params_gave_email);
    $result_gave_contact  = civicrm_api4('Contact','get', $params_gave_contact);
    $result_gave_email    = civicrm_api4('Phone',  'get', $params_gave_email);
    wachthond($extdebug,9, 'result_gave_email',       $result_gave_email);

    if (isset($related_gavecontact_id)) {
      $gave_email      = $result_gave_email[0]['email'] ?? NULL;
      wachthond($extdebug,2, 'gave_contact_email', $gave_contact_email);
    } else {
      $gavecontact_email  = "";
    }
*/
    ##########################################################################################
/*
    $params_rel_gave_get = [
      'checkPermissions' => FALSE,
      'debug'  => $apidebug,
      'select' => [
          'row_count',          
#         'email.email',
#         'phone.phone',
          'contact_id_b',
          'contact_id_b.display_name',
          'contact_id_b.image_URL',
          'contact_id_b.phone_primary',
          'contact_id_b.email_primary',
      ],
      'join' => [
#         ['Phone AS phone', 'INNER' ],
#         ['Email AS email', 'INNER' ],
#         ['Phone AS phone', 'INNER', ['contact_id', '=', 'contact_id_b']],
#         ['Email AS email', 'INNER', ['contact_id', '=', 'contact_id_b']],
      ],
        'where' => [
        ['contact_id_a',      '=', $contact_id],          
#         ['email.location_type_id',  '=', 1],
#         ['phone.location_type_id',  '=', 1],
        ['relationship_type_id',  '=', 20],         
        ],
    ];

    wachthond($extdebug,3, 'params_rel_gave_get',        $params_rel_gave_get);
    $result_rel_gave_get  = civicrm_api4('Relationship','get', $params_rel_gave_get);
    wachthond($extdebug,3, 'result_rel_gave_get',          $result_rel_gave_get);

    $rel_gave_rowcount  = $result_rel_gave_get[0]['row_count'] ?? NULL;
    wachthond($extdebug,2, 'rel_gave_rowcount',  $rel_gave_rowcount);

    if ($rel_gave_rowcount > 0) {
      $rel_gave_phone   = $result_rel_gave_get[0]['phone.phone'] ?? NULL;
      $rel_gave_email   = $result_rel_gave_get[0]['email.email'] ?? NULL;
      wachthond($extdebug,2, 'rel_gave_phone', $rel_gave_phone);
      wachthond($extdebug,2, 'rel_gave_email', $rel_gave_email);
    } else {
      $rel_gave_phone   = "";
      $rel_gave_email   = "";
    }
*/

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.9 B CREATE EMAIL PHONE_GAVE", "[groupID: $groupID] [op: $op]");
    wachthond($extdebug,2, "########################################################################");

    ##########################################################################################
    ### PHONE CHECK [GET GAVE PHONE]
    ##########################################################################################

    $params_phone_gave = [
      'checkPermissions' => FALSE,
      'debug'  => $apidebug,
        'select' => [
        'row_count', 'id', 'phone', 'contact_id.do_not_phone',
        ],
        'where' => [
        ['contact_id',        '=',  $contact_id],
        ['location_type_id:name',   '=',  "Gave"],
#           ['location_type_id',    '=',  26],
        ],
    ];
    wachthond($extdebug,7, 'params_phone_gave',         $params_phone_gave);
    $result_phone_gave  = civicrm_api4('Phone','get',   $params_phone_gave);
    wachthond($extdebug,9, 'result_phone_gave',         $result_phone_gave);

        $result_phone_count = $result_phone_gave[0]['row_count']        ?? NULL;
//      $result_phone_count = $result_phone->countMatched()             ?? NULL;

        if ($result_phone_count == 1) {
            $phone_gave_id        = $result_phone_gave[0]['id']                       ?? NULL;
            $phone_gave_phone     = $result_phone_gave[0]['phone']                    ?? NULL;
            $phone_gave_donot     = $result_phone_gave[0]['contact_id.do_not_phone']  ?? NULL;
            wachthond($extdebug,2, 'phone_gave_id',           $phone_gave_id);
            wachthond($extdebug,2, 'phone_gave_phone',        $phone_gave_phone);
            wachthond($extdebug,2, 'phone_gave_donot',        $phone_gave_donot);
        } else {
            $phone_gave_id        = "";
            $phone_gave_phone     = "";
            $phone_gave_donot     = "";
        }

    ##########################################################################################
    ### PHONE CHECK [PHONE GAVE AL IN ORDE]
    ##########################################################################################

    if ($phone_gave_id AND $phone_gave_id > 0) {

      ### EMAIL IN ORDE
      if ($phone_gave_phone == $new_phone_gave_phone) {
          wachthond($extdebug,2, 'phone_gave al in orde',   $new_phone_gave_phone);
      } else {

        ### EMAIL DELETE
        if ($phone_gave_phone != $part_notificatie_gave) {
/*
          $phone_phone_gave_delete = civicrm_api4('Phone', 'delete', [
            'where' => [
              ['id', '=', $phone_gave_id],
            ],
          ]);

            wachthond($extdebug,2, 'phone_gave verwijderd', "$phone_gave_phone ($phone_gave_id)");
          $phone_gave_removed = 1;
*/
        }
      }
      
      ##########################################################################################
      ### PHONE UPDATE
      ##########################################################################################

      if ($phone_gave_phone != $new_phone_gave_phone AND !empty($new_phone_gave_phone)) {
        $params_phone_gave_update = [
          'checkPermissions' => FALSE,
          'debug' => $apidebug,
          'where' => [
            ['id',          '=', $phone_gave_id],
            ['contact_id',      '=', $contact_id],
          ],
          'values' => [
            'phone'          =>  $new_phone_gave_phone,
            'location_type_id:name'  =>  "Gave",
            'is_primary'       =>  TRUE,
          ],
        ];
            wachthond($extdebug,7, 'params_phone_gave_update',        $params_phone_gave_update);
        if ($extwrite == 1 AND $phone_gave_donot == FALSE) {
            $result_phone_gave_update = civicrm_api4('Phone', 'update', $params_phone_gave_update);
          }
          wachthond($extdebug,9, 'result_phone_gave_update',        $result_phone_gave_update);
        wachthond($extdebug,2, 'phone_gave geupdated',          $new_phone_gave_phone);
      } else {
          wachthond($extdebug,9, 'phone_gave_update',     "SKIPPED");         
      }
    } else {
        wachthond($extdebug,9, 'phone_gave UPDATE',       "SKIPPED (geen phone_gave_id)");      
    }

    ##########################################################################################
    ### PHONE CREATE
    ##########################################################################################

    if ((empty($phone_gave_id) AND !empty($new_phone_gave_phone)) OR $phone_gave_removed == 1) {
      $params_phone_gave_create = [
        'checkPermissions' => FALSE,
        'debug'  => $apidebug,
        'values' => [
          'contact_id'       => $contact_id,
          'phone'          => $new_phone_gave_phone,
            'location_type_id:label' => 'Gave',
            'phone_type_id:label'    => 'Mobiel',
          'is_primary'       => TRUE,
        ],
      ];
          wachthond($extdebug,7, 'params_phone_gave_create',        $params_phone_gave_create);
      if ($extwrite == 1 AND !in_array($privacy_voorkeuren, array("33","44"))) {
          $result_phone_gave_create = civicrm_api4('Phone', 'create', $params_phone_gave_create);
        } else {
          wachthond($extdebug,9, 'phone_gave_create',           "SKIPPED [ivm privacy]");
        }
        wachthond($extdebug,9, 'result_phone_gave_create',      $result_phone_gave_create);
        wachthond($extdebug,2, 'phone_gave aangemaakt',           $new_phone_gave_phone);
    } else {
        wachthond($extdebug,9, 'phone_gave_create',             "SKIPPED");
        wachthond($extdebug,9, 'phone_gave_id',                 $phone_gave_id);
        wachthond($extdebug,9, 'new_phone_gave_phone',          $new_phone_gave_phone);
        wachthond($extdebug,9, 'phone_gave_removed',            $phone_gave_removed);
    }

    watchdog('civicrm_timing', core_microtimer("EINDE configure REL/GAVE"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.10 CONFIGURE AANDACHTSPUNTEN MEDISCH",              "MEDISCH]");
    wachthond($extdebug,2, "########################################################################");

    watchdog('civicrm_timing', core_microtimer("START configuratie MEDISCH"), NULL, WATCHDOG_DEBUG);
    $result_medisch = medisch_civicrm_configure($contact_id);
    wachthond($extdebug,3, 'result_medisch',                    $result_medisch);
    watchdog('civicrm_timing', core_microtimer("EINDE configuratie MEDISCH"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.11 CONFIGURE AANDACHTSPUNTEN GEDRAG",                "[GEDRAG]");
    wachthond($extdebug,2, "########################################################################");

    watchdog('civicrm_timing', core_microtimer("START configuratie GEDRAG"), NULL, WATCHDOG_DEBUG);
    $result_gedrag = gedrag_civicrm_configure($contact_id);
    wachthond($extdebug,3, 'result_gedrag',                     $result_gedrag);
    watchdog('civicrm_timing', core_microtimer("EINDE configuratie GEDRAG"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.12 CONFIGURE FOT, NAW, BIO",                    "[FOT/NAW/BIO]");
    wachthond($extdebug,2, "########################################################################");

    watchdog('civicrm_timing', core_microtimer("START configuratie INTAKE"), NULL, WATCHDOG_DEBUG);
    $result_intake = intake_civicrm_configure($contact_id, $ditjaar_part_id);
    wachthond($extdebug,3, 'result_intake',                     $result_intake);
    watchdog('civicrm_timing', core_microtimer("EINDE configuratie INTAKE"), NULL, WATCHDOG_DEBUG);    

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.13 ENSURE CUSTOM CONTACT FIELD TABLE ENTRIES",      "[ENSURE]");
    wachthond($extdebug,2, "########################################################################");

    watchdog('civicrm_timing', core_microtimer("START ensure entries contact"), NULL, WATCHDOG_DEBUG);
    $result_ensure_contact = ensure_custom_rows_for_contact($contact_id);
    wachthond($extdebug,3, 'result_ensure_contact',             $result_ensure_contact);
    watchdog('civicrm_timing', core_microtimer("EINDE ensure entries contact"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.14 ENSURE CUSTOM PARTICIPANT FIELD TABLE ENTRIES",  "[ENSURE]");
    wachthond($extdebug,2, "########################################################################");

    if ($ditjaar_pos_part_id) {
        watchdog('civicrm_timing', core_microtimer("START ensure entries part"), NULL, WATCHDOG_DEBUG);
        $result_ensure_participant = ensure_custom_rows_for_participant($ditjaar_pos_part_id);
        wachthond($extdebug,3, 'result_ensure_participant',     $result_ensure_participant);
        watchdog('civicrm_timing', core_microtimer("EINDE ensure entries part"), NULL, WATCHDOG_DEBUG);
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.14 ENSURE CUSTOM CONTRIBUTION FIELD TABLE ENTRIES", "[ENSURE]");
    wachthond($extdebug,2, "########################################################################");

    // M61: dit staat hier nu te vroeg. BID wordt pas in 5 bekeken.

    if ($ditevent_lineitem_contribid) {
        $result_ensure_contribution = ensure_custom_rows_for_contribution($ditevent_lineitem_contribid);
        wachthond($extdebug,3, 'result_ensure_contribution',     $result_ensure_contribution);
    }

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.X EINDE CORRECT MISCELANEOUS VALUES", "[groupID: $groupID] [op: $op]");

  } else {
    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE 4.X SKIPPED CORRECT MISCELANEOUS VALUES", "[groupID: $groupID] [op: $op]");
  }

    watchdog('civicrm_timing', core_microtimer("EINDE segment 4.X CORRECT MISCELANEOUS VALUES"), NULL, WATCHDOG_DEBUG);

    ##########################################################################################
    # 4.X SEGMENT DEEL & LEID DIT EVENT (ELK JAAR) (OOK VOORGAANDE)
    ##########################################################################################

    if ($extacl == 1 AND in_array($groupID, $profilepart)) {

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### CORE 5.X SEGMENT DEEL & LEID DIT EVENT (ELK JAAR)", "[groupID: $groupID] [op: $op]");
        wachthond($extdebug,1, "########################################################################");

        watchdog('civicrm_timing', core_microtimer("START segment 5.X DEEL & LEID DIT EVENT"), NULL, WATCHDOG_DEBUG);

        wachthond($extdebug,3, 'diteventdeelyes',   $diteventdeelyes);
        wachthond($extdebug,3, 'diteventdeelmss',   $diteventdeelmss);
        wachthond($extdebug,3, 'diteventleidyes',   $diteventleidyes);
        wachthond($extdebug,3, 'diteventleidmss',   $diteventleidmss);

        wachthond($extdebug,3, 'ditjaardeelyes',    $ditjaardeelyes);
        wachthond($extdebug,3, 'ditjaardeelmss',    $ditjaardeelmss);
        wachthond($extdebug,3, 'ditjaarleidyes',    $ditjaarleidyes);
        wachthond($extdebug,3, 'ditjaarleidmss',    $ditjaarleidmss);
    }

    ##########################################################################################
    if ($extacl == 1 AND in_array($groupID, $profilepart)) {
    ##########################################################################################

    $toeristenbelasting = NULL;

    $params_contrib     = NULL;
    $contrib_id         = NULL;
    $saldo_bedrag       = NULL;
    $saldo_betaald      = NULL;
    $saldo_balans       = NULL;

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 5.1 VIND HET CONTRIBUTION ID DAT HOORT BIJ DEZE PARTICIPANT", "[groupID: $groupID] [op: $op]");
    wachthond($extdebug,2, "########################################################################");

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE METHODE 1: VIA LINE ITEM (JOIN PARTICIPANT / LINEITEM)");
    wachthond($extdebug,2, "########################################################################");

    $params_lineitem_getpartcontrib = [
        'checkPermissions' => FALSE,
        'debug' => $apidebug,       
        'select' => [
            'row_count',
            'id',
            'contribution_id',
            'entity_id',
            'contribution_id.receive_date',
            'contribution_id.contribution_status_id',
            'price_field_id',
            'price_field_id:label',
            'label',
            'contribution_id.net_amount',
            'contribution_id.paid_amount',
            'contribution_id.balance_amount',
            'contribution.contribution_status_id',
            'contribution_id.contribution_status_id:label',
            'entity_table',
        ],
        'join' => [
            ['Contribution AS contribution', 'INNER'],
        ],      
        'where' => [
            ['qty',                '=', 1],
            ['price_field_id:label',      'IN', ['Kampgeld', 'Kampgeld leiding', 'Inschrijfgeld Topkamp']],
            ['contribution_id.receive_date',  '>=', $eventkamp_fiscalyear_start],
            ['contribution_id.receive_date',  '<=', $eventkamp_fiscalyear_einde],
            ['entity_table',           '=', 'civicrm_participant'],
            ['entity_id',              '=', $ditevent_part_id],
      ],
    ];

    wachthond($extdebug,3, 'params_lineitem_getpartcontrib',      $params_lineitem_getpartcontrib);
    $result_lineitem_getpartcontrib = civicrm_api4('LineItem', 'get',   $params_lineitem_getpartcontrib);
    wachthond($extdebug,3, 'result_lineitem_getpartcontrib',      $result_lineitem_getpartcontrib);

    $participant_contrib_count = $result_lineitem_getpartcontrib->countMatched();
    wachthond($extdebug,3, "participant_contrib_count", $participant_contrib_count);

    if ($participant_contrib_count == 1) {
        $ditevent_lineitem_contribid    = $result_lineitem_getpartcontrib[0]['contribution_id']                 ?? NULL;
        $saldo_bedrag                   = $result_lineitem_getpartcontrib[0]['contribution_id.net_amount']      ?? NULL;
        $saldo_betaald                  = $result_lineitem_getpartcontrib[0]['contribution_id.paid_amount']     ?? NULL;
        $saldo_balans                   = $result_lineitem_getpartcontrib[0]['contribution_id.balance_amount']  ?? NULL;
        wachthond($extdebug,1, "ditevent_lineitem_contribid", $ditevent_lineitem_contribid);
        wachthond($extdebug,1, "saldo_bedrag",                $saldo_bedrag);
        wachthond($extdebug,1, "saldo_betaald",               $saldo_betaald);
        wachthond($extdebug,1, "saldo_balans",                $saldo_balans);
        $params_contact['values']['DITJAAR.ditjaar_bid']                = $ditevent_lineitem_contribid;
        $params_contact['values']['DITJAAR.ditjaar_bedrag']             = $saldo_bedrag;
        $params_contact['values']['DITJAAR.ditjaar_betaald']            = $saldo_betaald;
        $params_contact['values']['DITJAAR.ditjaar_balans']             = $saldo_balans;
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE METHODE 2: ZOEK CONTRIBUTION VIA CONTACTID EN RECEIVEDATE DIT JAAR");
    wachthond($extdebug,2, "########################################################################");

    $params_ditjaar_kampgeld = [
      'checkPermissions' => FALSE,
      'debug'  => $apidebug,
      'select' => [
            'row_count',
            'id',
            'net_amount',
            'receive_date',

            'contribution_status_id',
            'contribution_status_id:label',

            'contact_id.display_name',
            'contact_id.DITJAAR.ditjaar_event_kampkort',
            'contact_id.DITJAAR.ditjaar_event_kamptype_id',

            'CONT_KAMPGELD.kampkort',
            'CONT_KAMPGELD.eventjaar',
      ],
        'where' => [
          ['financial_type_id:label',   'IN',   ['Kampgeld', 'Kampgeld leiding', 'Inschrijfgeld Topkamp']],
          ['contact_id',          '=',          $contact_id],
          ['receive_date',        '>=',         $eventkamp_fiscalyear_start],
          ['receive_date',        '<=',         $eventkamp_fiscalyear_einde],
#         ['CONT_KAMPGELD.eventjaar',   '>=',   $eventkamp_fiscalyear_start],
#         ['CONT_KAMPGELD.eventjaar',   '<=',   $eventkamp_fiscalyear_einde],
#         ['CONT_KAMPGELD.kamptype_id',   '=',  $eventkamp_kamptype_id],          
        ],
    ];

    wachthond($extdebug,7, 'params_ditjaar_kampgeld',         $params_ditjaar_kampgeld);
    $result_ditjaar_kampgeld = civicrm_api4('Contribution', 'get',  $params_ditjaar_kampgeld);
    wachthond($extdebug,9, 'result_ditjaar_kampgeld',         $result_ditjaar_kampgeld);

    $ditjaar_kampgeld_count  = $result_ditjaar_kampgeld->countMatched();
      wachthond($extdebug,3, "ditjaar_kampgeld_count",        $ditjaar_kampgeld_count);

    if ($ditjaar_kampgeld_count == 1) {
        $ditjaar_kampgeld_one_contribid     = $result_ditjaar_kampgeld[0]['id']                             ?? NULL;
        $ditjaar_kampgeld_one_receive_date  = $result_ditjaar_kampgeld[0]['receive_date']                   ?? NULL;
        $ditjaar_kampgeld_one_net_amount    = $result_ditjaar_kampgeld[0]['net_amount']                     ?? NULL;
        $ditjaar_kampgeld_one_status_id     = $result_ditjaar_kampgeld[0]['contribution_status_id']         ?? NULL;
        $ditjaar_kampgeld_one_status_label  = $result_ditjaar_kampgeld[0]['contribution_status_id:label']   ?? NULL;
        wachthond($extdebug,1, "ditjaar_kampgeld_one_contribid",    $ditjaar_kampgeld_one_contribid);
        wachthond($extdebug,1, "ditjaar_kampgeld_one_receive_date", $ditjaar_kampgeld_one_receive_date);
        wachthond($extdebug,1, "ditjaar_kampgeld_one_net_amount",   $ditjaar_kampgeld_one_net_amount);
        wachthond($extdebug,1, "ditjaar_kampgeld_one_status_id",    $ditjaar_kampgeld_one_status_id);
        wachthond($extdebug,1, "ditjaar_kampgeld_one_status_label", $ditjaar_kampgeld_one_status_label);
    }

    wachthond($extdebug,2, "########################################################################");

    if ($ditevent_lineitem_contribid   > 0 AND $participant_contrib_count == 1)   {
        wachthond($extdebug,1, "ditevent_lineitem_contribid",     "01 PARTCONTRIB GEVONDEN [$ditevent_lineitem_contribid]");
    } elseif ($participant_contrib_count > 1) {
        wachthond($extdebug,1, "ditevent_lineitem_contribid",     ">1 PARTCONTRIB GEVONDEN");
    } elseif ($participant_contrib_count == 0) {
        wachthond($extdebug,1, "ditevent_lineitem_contribid",     "00 PARTCONTRIB GEVONDEN [$participant_contrib_count]");
    } else {
        wachthond($extdebug,1, "ditevent_lineitem_contribid",     "XX PARTCONTRIB GEVONDEN [$participant_contrib_count]");      
    }

    if ($ditjaar_kampgeld_one_contribid > 0 AND $ditjaar_kampgeld_count   == 1)   {
        wachthond($extdebug,1, "ditjaar_kampgeld_one_contribid",  "01 KAMPGELD DITJAAR GEVONDEN [$ditjaar_kampgeld_one_contribid]");
    } elseif ($ditjaar_kampgeld_count   > 1) {
        wachthond($extdebug,1, "ditjaar_kampgeld_one_contribid",  ">1 KAMPGELD DITJAAR GEVONDEN");      
    } elseif ($ditjaar_kampgeld_count   == 0) {
        wachthond($extdebug,1, "ditjaar_kampgeld_one_contribid",  "00 KAMPGELD DITJAAR GEVONDEN [$ditjaar_kampgeld_count]");
    } else {
        wachthond($extdebug,1, "ditjaar_kampgeld_one_contribid",  "XX KAMPGELD DITJAAR GEVONDEN [$ditjaar_kampgeld_count]");
    }

    wachthond($extdebug,2, "########################################################################");

    if ($ditevent_lineitem_contribid  > 0 AND $participant_contrib_count == 1)  {
      $ditevent_contribid = $ditevent_lineitem_contribid;
        wachthond($extdebug,1, "ditevent_contribid (GEBRUIK PART LINEITEM CONTRIB ID)",   $ditevent_contribid);
    }
    if ($ditjaar_kampgeld_one_contribid > 0 AND $ditjaar_kampgeld_count   == 1 AND $participant_contrib_count != 1)   {
      $ditevent_contribid = $ditjaar_kampgeld_one_contribid;
        wachthond($extdebug,1, "ditevent_contribid (GEBRUIK DITJAAR ONE CONTRIB ID)",     $ditevent_contribid);
    }

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,3, 'params_contact_dusver', $params_contact);
    wachthond($extdebug,4, "########################################################################");

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 5.2 a BEPAAL OF ER TOERISTENBELASTING NODIG IS",         "[EDE]");
    wachthond($extdebug,2, "########################################################################");

    $params_addresses = [
      'checkPermissions' => FALSE,
      'debug' => $apidebug,
        'select' => [
          'row_count',
        ],
        'where' => [
          ['contact_id',        '=', $contact_id],
          ['Adresgegevens.Gemeente',  '=', 'Ede'], 
        ],
    ];
    wachthond($extdebug,7, 'params_addresses', $params_addresses);
    $result_ede   = civicrm_api4('Address', 'get', $params_addresses);
    $group_ede    = $result_ede->countMatched();
    wachthond($extdebug,9, 'result_addresses', $result_ede);
    wachthond($extdebug,1, "group_ede",      $group_ede);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 5.2 b BEPAAL OF ER TOERISTENBELASTING NODIG IS",        "[GAVE]");
    wachthond($extdebug,2, "########################################################################");

    if ($ditevent_lineitem_contribid > 0) {

        $params_lineitem = [
            'checkPermissions' => FALSE,
            'debug' => $apidebug,       
            'select' => [
                'row_count',
            ],
            'where' => [
                ['qty', '=', 1],
                ['contribution_id',               '=', $ditevent_lineitem_contribid],
                ['contribution_id.contact_id',    '=', $contact_id],
                ['contribution_id.receive_date', '>=', $eventkamp_fiscalyear_start],
                ['contribution_id.receive_date', '<=', $eventkamp_fiscalyear_einde],
                ['price_field_value_id',         'IN', [302, 301, 498, 472, 473, 497]],
            ],
        ];
        wachthond($extdebug,7, 'params_gave',             $params_lineitem);
        $result_gave  = civicrm_api4('LineItem', 'get',   $params_lineitem);
        $group_gave   = $result_gave->countMatched();
        wachthond($extdebug,9, 'result_gave',   $result_gave);
        wachthond($extdebug,3, "group_gave",  $group_gave);

        if ($group_gave >= 1) {

            wachthond($extdebug,2, "TOERISTENBELASTING: [3] via St.Gave", "NEE");
            $toeristenbelasting = 3;
            $kampgeldregeling         = "ja_stgave";
            $kampgeld_lineitem_gave   = "ja_stgave";
            wachthond($extdebug,3, "kampgeldregeling", $kampgeldregeling);

        } elseif ($diteventdeelyes == 1 AND $group_ede == 0) {

            $toeristenbelasting = 1;
            wachthond($extdebug,2, "TOERISTENBELASTING: [1] als deelnemer", "JA");

        } elseif ($diteventleidyes == 1 AND $group_ede == 0) {

            $toeristenbelasting = 2;
            wachthond($extdebug,2, "TOERISTENBELASTING: [2] als leiding", "JA");

        } elseif ($group_ede == 1) {

            $toeristenbelasting = 4;
            wachthond($extdebug,2, "TOERISTENBELASTING: [4] inwoner Ede", "NEE");

        } elseif ($diteventdeelmss) {
            $toeristenbelasting = ""; 
        }

        if ($toeristenbelasting) {
            $params_toer = [
                'checkPermissions' => FALSE,
                'debug' => $apidebug,
                'where' => [
                    ['id', '=', $ditevent_part_id],
                ],
                'values' => [
                    'PART.PART_Toeristenbelasting'  => $toeristenbelasting,
                ],
            ];
        }

        if ($ditevent_part_id AND $toeristenbelasting) {
            wachthond($extdebug,7, 'params_toer', $params_toer);
            #$params_part_ditevent['values']['PART.PART_Toeristenbelasting'] = $toeristenbelasting;
            #$result_toer = civicrm_api4('Participant', 'update', $params_toer);
            #if ($result_toer) { wachthond($extdebug,9, 'result_toer', $result_toer); }
        }

        if ($ditevent_part_id AND $toeristenbelasting) {
            wachthond($extdebug,7, 'params_toer', $params_toer);
            #$params_part_ditevent['values']['PART.PART_Toeristenbelasting'] = $toeristenbelasting;
            #$result_toer = civicrm_api4('Participant', 'update', $params_toer);
            #if ($result_toer) { wachthond($extdebug,9, 'result_toer', $result_toer); }
        }
/*      
        // M61: INDIEN ST.GAVE ZET DAN REGELING PART OP ST.GAVE
        if ($ditevent_part_id AND $kampgeldregeling) {
            $params_part_ditevent['values']['PART_KAMPGELD.regeling']   = $kampgeldregeling;
        }
*/
        wachthond($extdebug,1, 'toeristenbelasting', $toeristenbelasting);

        wachthond($extdebug,2, "########################################################################");

        ### ZET DE REGELING VAN DE CONTRIBUTIE OP STGAVE INDIEN NODIG EN GEBRUIK ANDERS DE REGELING UIT PART KAMPGELD

        if ($kampgeld_lineitem_gave == 'ja_stgave') {
            $new_kampgeldregeling   = 'ja_stgave';
            $stgave         = 'ja';
        } elseif (empty($ditevent_part_kampgeld_regeling)) {
            $new_kampgeldregeling   = 'nee';
        } else {
            $new_kampgeldregeling   = $ditevent_part_kampgeld_regeling;
        }

        if ($new_kampgeldregeling) {
            $params_update_regeling = [
            'checkPermissions' => FALSE,
            'debug' => $apidebug,
            'where' => [
                ['id', '=', $ditevent_part_id],
            ],
            'values' => [
                'PART_KAMPGELD.regeling' => $new_kampgeldregeling,
            ],
        ];

        if ($ditjaardeelyes == 1) {

            wachthond($extdebug,7, 'params_update_regeling',        $params_update_regeling);
            $result_update_regeling = civicrm_api4('Participant', 'update', $params_update_regeling);
            wachthond($extdebug,9, 'result_update_regeling',        $result_update_regeling);
            $params_part_ditevent['values']['PART_KAMPGELD.regeling']     =   $new_kampgeldregeling;
            $params_contact['values']['DITJAAR.ditjaar_regeling']         =   $new_kampgeldregeling;

        }

        wachthond($extdebug,2, "ditevent_part_kampgeld_regeling",   $ditevent_part_kampgeld_regeling);
        wachthond($extdebug,2, "kampgeldregeling",                  $kampgeldregeling);
        wachthond($extdebug,2, "new_kampgeldregeling",              $new_kampgeldregeling);
    }

    if ($new_kampgeldregeling == 'ja_stgave') {

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### CORE - SET IS_PRIMARY = 1 VOOR EMAIL CONTACTPERSOON STGAVE (LOC.TYPE.26)");
        wachthond($extdebug,2, "########################################################################");

        wachthond($extdebug,1, 'kampgeldregeling',                  $kampgeldregeling);
        wachthond($extdebug,1, 'new_kampgeldregeling',              $new_kampgeldregeling);
        wachthond($extdebug,1, 'ditevent_part_kampgeld_regeling',   $ditevent_part_kampgeld_regeling);

        if ($email_gave_id > 0 AND $leeftijd_vantoday_rondjaren >= 6 AND $leeftijd_vantoday_rondjaren < 18) {
            $params_email_gave_update = [
                'checkPermissions' => FALSE,
                'debug' => $apidebug,
                'where' => [
                    ['id',          '=', $email_gave_id],
                    ['contact_id',      '=', $contact_id],
                ],
                'values' => [
                    'location_type_id:name'  =>  "Gave",
                    'is_primary'       =>  TRUE,
                ],
            ];
            wachthond($extdebug,7, 'params_email_gave_update',        $params_email_gave_update);
            if ($extwrite == 1 AND !in_array($privacy_voorkeuren, array("33","44"))) {
                $result_email_gave_update = civicrm_api4('Email', 'update', $params_email_gave_update);
            }
            wachthond($extdebug,9, 'result_email_gave_update',  $result_email_gave_update);
            wachthond($extdebug,2, 'EMAIL_GAVE geupdated',    "[is_primary = 1 voor $email_gave_email]");
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### CORE SET IS_PRIMARY = 0 TO OTHER EMAILS VAN DEZE ST.GAVE DEELNEMER");
        wachthond($extdebug,2, "########################################################################");

        if ($email_gave_id > 0 AND $leeftijd_vantoday_rondjaren >= 6 AND $leeftijd_vantoday_rondjaren < 18) {
            $params_email_gave_update = [
                'checkPermissions' => FALSE,
                'debug' => $apidebug,
                'where' => [
                    ['contact_id',        '=',  $contact_id],
                    ['location_type_id:name',   '!=',   "Gave"],
                ],
                'values' => [
                    'is_primary'       =>  FALSE,
                ],
            ];
            wachthond($extdebug,7, 'params_email_gave_update',          $params_email_gave_update);
            $result_email_gave_update = civicrm_api4('Email', 'update', $params_email_gave_update);
            wachthond($extdebug,9, 'result_email_gave_update',          $result_email_gave_update);
            wachthond($extdebug,2, 'EMAIL_GAVE geupdated',        "[is_primary = 0 voor andere emals]");
        }

    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 5.2 d BEPAAL OF ER EEN FIETS MOET WORDEN GEHUURD", "[$displayname $ditevent_part_functie $ditevent_event_kampkort]");
    wachthond($extdebug,2, "########################################################################");

    if ($ditjaar_event_fietsevent == 1) {

        $fietshuur              = NULL;
        $price_field_value_id   = NULL;
        $new_cont_fietshuur     = NULL;
        $new_part_fietshuur     = NULL;

        ### HAAL EERST DE WAARDEN OP UIT PART KAMPGELD

        wachthond($extdebug,1, 'ditjaar_event_fietsevent',          $ditjaar_event_fietsevent);
        wachthond($extdebug,1, 'ditevent_event_fietsevent',         $ditevent_event_fietsevent);
        wachthond($extdebug,1, 'ditjaar_part_kampgeld_fietshuur',   $ditjaar_part_kampgeld_fietshuur);
        wachthond($extdebug,1, 'ditevent_part_kampgeld_fietshuur',  $ditevent_part_kampgeld_fietshuur);

        if ($ditjaar_part_kampgeld_fietshuur == 'zelffiets') {
            $new_cont_fietshuur = "zelffiets";
            $new_part_fietshuur = "zelffiets";
            wachthond($extdebug,4, "Ik neem zelf een fiets mee",    "[$new_cont_fietshuur]");
        }

        if ($ditjaar_part_kampgeld_fietshuur == 'fietshuur') {
            $new_cont_fietshuur = "fietshuur";
            $new_part_fietshuur = "fietshuur";
            wachthond($extdebug,4, "Ik wil graag een fiets huren",  "[$new_cont_fietshuur]");
        }

        if ($ditjaar_part_kampgeld_fietshuur == 'alsnoghuren') {
            $new_cont_fietshuur = "alsnoghuren";     
            $new_part_fietshuur = "alsnoghuren";     
            wachthond($extdebug,4, "Ik wil alsnog een fiets huren", "[$new_cont_fietshuur]");
        }        

        wachthond($extdebug,3, 'new_cont_fietshuur 0',    $new_cont_fietshuur);
        wachthond($extdebug,3, 'new_part_fietshuur 0',    $new_part_fietshuur);

        ### CHECK DAN OF ER EEN LINE_ITEM IN DE CONTRIBUTION IS DIE DUIDT OP FIETSHUUR

        $params_lineitem_fiets = [
            'checkPermissions' => FALSE,
            'debug' => $apidebug,       
            'select' => [
                'row_count',
                'id',
                'contribution_id',
                'contribution_id.receive_date',
                'contribution_id.contribution_status_id',
                'contribution_id.contribution_status_id:label',
                'price_field_id:name',
                'price_field_id:label',
                'price_field_id',
                'label',
                'source',
                'price_field_value_id',
                'unit_price',
                'financial_type_id',
                'custom.*',
                'entity_table',
                'entity_id',
            ],
            'join' => [
                ['Contribution AS contribution', 'INNER'],
            ],      
            'where' => [
                ['qty',                             '=', 1],
                ['price_field_id',                  'IN', [29, 102]],
                ['contribution_id.receive_date',    '>=', $eventkamp_fiscalyear_start],
                ['contribution_id.receive_date',    '<=', $eventkamp_fiscalyear_einde],
                ['entity_table',                    '=', 'civicrm_participant'],
                ['entity_id',                       '=',  $ditevent_part_id],
            ],
        ];

        ### PRICE_FIELD_ID
        ### 29  = KAMPGELD DEEL
        ### 102 = KAMPGELD LEID
        ### 153 = ALSNOG FIETS

        wachthond($extdebug,7, 'params_lineitem_fiets',           $params_lineitem_fiets);
        $result_lineitem_fiets = civicrm_api4('LineItem','get',   $params_lineitem_fiets);
        wachthond($extdebug,9, 'result_lineitem_fiets',           $result_lineitem_fiets);
        $count_fiets        = $result_lineitem_fiets->countMatched();
        wachthond($extdebug,3, "count_fiets", $count_fiets);

        if ($count_fiets >= 1) {

            $fietshuur  = $result_lineitem_fiets[0]['label']        ?? NULL;
            $unit_price = $result_lineitem_fiets[0]['unit_price']   ?? NULL;

            wachthond($extdebug,1, 'fietshuur',     $fietshuur);
            wachthond($extdebug,1, 'unit_price',    $unit_price);
    
            if ($unit_price == 0) {
                $new_cont_fietshuur = "zelffiets";
                $new_part_fietshuur = "zelffiets";
                wachthond($extdebug,1, "Ik neem zelf een fiets mee",    "[$new_cont_fietshuur]");
            }
            if ($unit_price > 0) {
                $new_cont_fietshuur  = "fietshuur";
                $new_part_fietshuur  = "fietshuur";
                wachthond($extdebug,1, "Ik wil graag een fiets huren",  "[$new_cont_fietshuur]");
            }
        }

        wachthond($extdebug,3, 'new_cont_fietshuur 1',    $new_cont_fietshuur);
        wachthond($extdebug,3, 'new_part_fietshuur 1',    $new_part_fietshuur);

        ##########################################################################################
        ### M61: Indien later een fiets is bijgeboekt staat deze als losse contribution en staat in part_kampgeld 'alsnoghuren'
        ##########################################################################################

        $params_lineitem_alsnogfiets = [
            'checkPermissions' => FALSE,
            'debug' => $apidebug,       
            'select' => [
                'row_count',
                'id',
                'contribution_id',
                'contribution_id.contact_id',
                'contribution_id.receive_date',
                'contribution_id.contribution_status_id',
                'contribution_id.contribution_status_id:label',
                'price_field_id:name',
                'price_field_id:label',
                'price_field_id',
                'label',
                'source',
                'price_field_value_id',
                'unit_price',
                'financial_type_id',
                'custom.*',
                'entity_table',
                'entity_id',
            ],
            'join' => [
                ['Contribution AS contribution', 'INNER'],
            ],      
            'where' => [
                ['qty',                                     '=', 1],
                ['financial_type_id',                       '=', 12],
                ['unit_price',                              '>', 0], 
                ['contribution_id.contribution_status_id',  '=', 1],    // STATUS: COMPLETED
                ['contribution_id.receive_date',            '>=', $eventkamp_fiscalyear_start],
                ['contribution_id.receive_date',            '<=', $eventkamp_fiscalyear_einde],
                ['entity_table',                            '=', 'civicrm_contribution'],
                ['contribution_id.contact_id',              '=',  $contact_id],
            ],
        ];

        ### PRICE_FIELD_ID
        ### 29  = KAMPGELD DEEL
        ### 102 = KAMPGELD LEID
        ### 153 = ALSNOG FIETS

        wachthond($extdebug,7, 'params_lineitem_alsnogfiets',           $params_lineitem_alsnogfiets);
        $result_lineitem_alsnogfiets = civicrm_api4('LineItem','get',   $params_lineitem_alsnogfiets);
        wachthond($extdebug,9, 'result_lineitem_alsnogfiets',           $result_lineitem_alsnogfiets);
        $count_alsnogfiets           = $result_lineitem_alsnogfiets->countMatched();
        wachthond($extdebug,3, "count_alsnogfiets", $count_alsnogfiets);

        if ($count_alsnogfiets == 1) {
            $new_cont_fietshuur = "alsnoghuren";     
            $new_part_fietshuur = "alsnoghuren";     
            wachthond($extdebug,1, "Ik wil alsnog een fiets huren", "[$new_cont_fietshuur]");
        }

        wachthond($extdebug,3, 'new_cont_fietshuur 2',    $new_cont_fietshuur);
        wachthond($extdebug,3, 'new_part_fietshuur 2',    $new_part_fietshuur);

        ### UPDATE WAARDEN IN PART & CONT MET DE ACTUELE WENSEN

        if ($new_cont_fietshuur OR $ditjaar_event_fietsevent) {
            $params_cont_update_fietshuur = [
                'checkPermissions' => FALSE,
                'debug' => $apidebug,
                'where' => [
                    ['id', '=', $contact_id],
                ],
                'values' => [
                    'id'                            => $contact_id,
                    'DITJAAR.ditjaar_fietsevent'    => $ditjaar_event_fietsevent,
                    'DITJAAR.ditjaar_fietshuur'     => $new_cont_fietshuur,
                ],
            ];
            wachthond($extdebug,3, 'params_cont_update_fietshuur',              $params_cont_update_fietshuur);
            //$result_cont_update_fietshuur = civicrm_api4('Contact','update',    $params_cont_update_fietshuur);
            wachthond($extdebug,9, 'result_cont_update_fietshuur',              $result_cont_update_fietshuur);
        }

        if ($new_part_fietshuur) {
            $params_part_update_fietshuur = [
                'checkPermissions'  => FALSE,
                'debug' => $apidebug,
                'where' => [
                    ['id',  '=', $ditevent_part_id],
                ],
                'values' => [
                    'id'                        =>  $ditevent_part_id,
                    'PART_KAMPGELD.fietshuur'   =>  $new_part_fietshuur,
                ],
            ];
            if ($diteventdeelyes OR $diteventleidyes) {
                wachthond($extdebug,3, 'params_part_update_fietshuur',              $params_part_update_fietshuur);
                //$result_part_update_fietshuur = civicrm_api4('Participant','update',$params_part_update_fietshuur);
                wachthond($extdebug,9, 'result_part_update_fietshuur',              $result_part_update_fietshuur);        
            }
        };

        wachthond($extdebug,4, 'new_cont_fietshuur F',    $new_cont_fietshuur);
        wachthond($extdebug,4, 'new_part_fietshuur F',    $new_part_fietshuur);

        if ($diteventdeelyes OR $diteventleidyes) {
            if ($new_part_fietshuur) {
                $params_part_ditevent['values']['PART_KAMPGELD.fietshuur']  = $new_part_fietshuur;
                wachthond($extdebug,1, 'new_part_fietshuur',                  $new_part_fietshuur);
            } else {
                $params_part_ditevent['values']['PART_KAMPGELD.fietshuur']  = "";
                wachthond($extdebug,1, 'diteventleidyes',       $diteventleidyes); 
                wachthond($extdebug,1, 'diteventdeelyes',       $diteventdeelyes); 
                wachthond($extdebug,1, 'new_part_fietshuur',    "niet van toepassing"); 
            }
        }

        if ($ditjaardeelyes == 1 OR $ditjaarleidyes == 1) {
            if ($new_cont_fietshuur) {
                $params_contact['values']['DITJAAR.ditjaar_fietshuur']    = $new_cont_fietshuur;
                wachthond($extdebug,1, 'new_cont_fietshuur',                $new_cont_fietshuur);
            } else {
                $params_contact['values']['DITJAAR.ditjaar_fietshuur']    = "";
                wachthond($extdebug,1, 'ditjaarleidyes',        $ditjaarleidyes); 
                wachthond($extdebug,1, 'ditjaardeelyes',        $ditjaardeelyes); 
                wachthond($extdebug,1, 'new_cont_fietshuur',    "niet van toepassing"); 
            }
        }

        wachthond($extdebug,1, 'params_part_fietshuur',     $params_part_ditevent['values']['PART_KAMPGELD.fietshuur']); 
        wachthond($extdebug,1, 'params_cont_fietshuur',     $params_contact['values']['DITJAAR.ditjaar_fietshuur']); 

    } else {
        wachthond($extdebug,1, 'ditjaar_event_fietsevent',  $ditjaar_event_fietsevent);
        $params_contact['values']['DITJAAR.ditjaar_fietshuur']      = "";
        $params_part_ditevent['values']['PART_KAMPGELD.fietshuur']  = "";

    }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,1, "### CORE 5.4 TRIGGER EEN UPDATE VAN DE CONTRIBUTION",     "[$contrib_id]");
        wachthond($extdebug,2, "########################################################################");

        if ($contrib_id) {
            wachthond($extdebug,1, "Core: Triggering Pecunia via update for contrib", $contrib_id);
            
            // Voorbereiden van de parameters
            $params_contrib = [
                'checkPermissions' => FALSE,
                'where' => [['id', '=', $contrib_id]],
                'values' => [
                    // Dit veld zorgt dat de hook in Pecunia afgaat
                    'CONT_KAMPGELD.trigger_cont_kampgeld' => date('Y-m-d H:i:s'), 
                ],
            ];

            // Log de start van de database schrijf-actie
            watchdog('civicrm_timing', core_microtimer("START EXECUTE Contribution Update (Sectie 99)"), NULL, WATCHDOG_DEBUG);
            wachthond($extdebug, 7, 'params_contrib', $params_contrib);

            // De feitelijke API-aanroep
            // $result_contrib = civicrm_api4('Contribution', 'update', $params_contrib);

            // Log het resultaat en de eindtijd
            wachthond($extdebug, 9, 'result_contrib', $result_contrib);
            watchdog('civicrm_timing', core_microtimer("EINDE EXECUTE Contribution Update (Sectie 99)"), NULL, WATCHDOG_DEBUG);
        }

        if ($new_kampgeldregeling == 'ja_stgave') {
            $saldo_bedrag   = 0;
            $saldo_betaald  = 0;
            $saldo_balans   = 0;
        }

    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### CORE 5.5 SAVE CORRECT PARTICIPANT ROLE ID", "[$displayname $ditevent_part_functie $ditevent_event_kampkort]");
    wachthond($extdebug,2, "########################################################################");

    # $arrayint         = array_intersect($ditevent_part_role_id, array("6", "9"));
    # $arrayintcount    = count(array_intersect($ditevent_part_role_id, array("6", "9")));
    # if ($extdebug >= 2) { watchdog('php','<pre>arrayint: '.print_r($arrayint,TRUE).'</pre>',NULL,WATCHDOG_DEBUG);       }
    # if ($extdebug >= 2) { watchdog('php','<pre>arrayintcount: '.print_r($arrayintcount,TRUE).'</pre>',NULL,WATCHDOG_DEBUG);}

    # if ((count(array_intersect($ditevent_part_role_id, array("6", "9"))) > 0 ) OR in_array($ditevent_part_role_id, array("6", "9"))) {
        // ROLE_ID = LEIDING OF LEIDING TOPKAMP
        # M61: HIERBOVEN COMPLEXE MANIER OM twee arrrays te intersecten maar ook om te gaan als ditevent_part_role_id geen array is
    # }

    wachthond($extdebug,4, 'part_eventtypeid',      $ditevent_event_type_id);

    wachthond($extdebug,4, 'eventypesdeel',         $eventtypesdeel);
    wachthond($extdebug,4, 'eventypesdeeltest',     $eventtypesdeeltest);
    wachthond($extdebug,4, 'eventypesdeeltop',      $eventtypesdeeltop);
    wachthond($extdebug,4, 'eventypesdeeltoptest',  $eventtypesdeeltoptest);
    wachthond($extdebug,4, 'eventypesleid',         $eventtypesleid);
    wachthond($extdebug,4, 'eventypesleidtest',     $eventtypesleidtest); 

    ### DEFINE PART_FUNCTIE & PART_ROL

    if (in_array($ditevent_event_type_id, $eventtypesdeelall)) {
      $ditevent_part_functie    = 'deelnemer';
      $ditevent_part_rol        = 'deelnemer';
    }
    if (in_array($ditevent_event_type_id, $eventtypesleidall)) {
      $ditevent_part_functie    = $ditevent_leid_functie;
      $ditevent_part_rol        = 'leiding';
    }

    ### DEFINE PART ROLE_ID DEEL

    if (in_array($ditevent_event_type_id, $eventtypesdeel)) {
      $ditevent_rol_id      = ['Deelnemer'];
    }
    if (in_array($ditevent_event_type_id, $eventtypesdeeltest)) {
      $ditevent_rol_id      = ['Deelnemer'];
    }
    if (in_array($ditevent_event_type_id, $eventtypesdeeltoptest)) {
      $ditevent_rol_id      = ['Deelnemer', 'Deelnemer Topkamp'];
    }

    ### DEFINE PART ROLE_ID LEID

    if (in_array($ditevent_event_type_id, $eventtypesleidall)) {
      $ditevent_rol_id      = ['Leiding'];
    }
    if (in_array($ditevent_part_functie, array('hoofdleiding'))) {
      $ditevent_rol_id      = ['Leiding', 'Hoofdleiding'];
    }
    if ($ditevent_leid_welkkamp == 'TOP') {
      $ditevent_rol_id      = ['Leiding', 'Hoofdleiding', 'Leiding Topkamp'];
    }

    wachthond($extdebug,1, 'ditevent_part_functie',     $ditevent_part_functie);
    wachthond($extdebug,1, 'ditevent_part_rol',         $ditevent_part_rol);
    wachthond($extdebug,1, 'ditevent_rol_id',           $ditevent_rol_id);

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

        $birthdate_month  = date('m',     strtotime($birth_date));
        $birthdate_day    = date('d',     strtotime($birth_date));

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

        watchdog('civicrm_timing', core_microtimer("EINDE segment 5.X DEEL & LEID DIT EVENT"), NULL, WATCHDOG_DEBUG);

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### CORE 5.X EINDE SEGMENT DITEVENT (ELK JAAR)", "[groupID: $groupID] [op: $op]");
        wachthond($extdebug,1, "########################################################################");

    } else {

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### CORE 5.X SKIPPED SEGMENT DITEVENT (ELK JAAR)", "[groupID: $groupID] [op: $op]");
        wachthond($extdebug,1, "########################################################################");    
    } 

    ##########################################################################################
    if (in_array($groupID, $profilecv) AND $extdjcont == 1) {     // PROFILE CONT + PART (BASIC)
    ##########################################################################################

        watchdog('civicrm_timing', core_microtimer("START segment 8.X UPDATE PARAMS"), NULL, WATCHDOG_DEBUG);

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

        wachthond($extdebug,3, "params_contact",            $params_contact);
        wachthond($extdebug,3, "params_part_ditevent",      $params_part_ditevent);
        wachthond($extdebug,3, "params_part_ditjaar",       $params_part_ditjaar);

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
            if ($todaydatetime < date("Y") . "-06-20 00:00:00") {

                if (empty($ditjaar_part_groepsletter))     { $ditjaar_part_groepsletter   = $default_groepsletter;  }
                if (empty($ditjaar_part_groepskleur))      { $ditjaar_part_groepskleur    = $default_groepskleur;   }
                if (empty($ditjaar_part_groepsnaam))       { $ditjaar_part_groepsnaam     = $default_groepsnaam;    }
            }

            // Deze code runt alleen als de datum van het huidige jaar > 1 juli
            if ($todaydatetime > date("Y") . "-07-01 00:00:00") {

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

            $params_contact['values']['DITJAAR.ditjaar_deelnamestatus']     = "";
            $params_contact['values']['DITJAAR.ditjaar_regdate']            = ""; 

            $params_contact['values']['DITJAAR.ditjaar_wachtlijst_erop']    = ""; 
            $params_contact['values']['DITJAAR.ditjaar_wachtlijst_eraf']    = ""; 

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

            $params_contact['values']['DITJAAR.ditjaar_leeftijd']           = "";
            $params_contact['values']['DITJAAR.ditjaar_school']             = "";
            $params_contact['values']['DITJAAR.ditjaar_criteria_indicatie'] = "";
            $params_contact['values']['DITJAAR.ditjaar_criteria_oordeel']   = "";

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
/*
        $params_contact['values']['first_name']         = $first_name;
        $params_contact['values']['middle_name']        = $middle_name;
        $params_contact['values']['last_name']          = $last_name;

        if ($contactname) { $params_contact['values']['PRIVACY.contactnaam']    = $contactname;       }
*/

        if ($displayname) {
            $params_contact['values']['PRIVACY.naam']                       = $displayname;       
        }

        if ($familienaam) {
            $params_contact['values']['PRIVACY.familienaam']                = $familienaam;
        }

        if ($leeftijd_nextkamp_decimalen > 0) {
            $params_contact['values']['WERVING.nextkamp_decimalen']         = $leeftijd_nextkamp_decimalen;
            $params_contact['values']['WERVING.nextkamp_rondjaren']         = $leeftijd_nextkamp_rondjaren;
            $params_contact['values']['WERVING.nextkamp_rondmaand']         = $leeftijd_nextkamp_rondmaand;
        }
        if ($leeftijd_vantoday_decimalen > 0) {
            $params_contact['values']['WERVING.leeftijd_decimalen']         = $leeftijd_vantoday_decimalen;
            $params_contact['values']['WERVING.leeftijd_rondjaren']         = $leeftijd_vantoday_rondjaren;
        }

//      $params_contact['values']['INTAKE.NAW_nodig']                       = $new_ditjaar_nawnodig;
//      $params_contact['values']['INTAKE.BIO_nodig']                       = $new_ditjaar_bionodig;

//      $params_contact['values']['INTAKE.NAW_gecheckt']                    = $new_ditjaar_nawgecheckt;
//      $params_contact['values']['INTAKE.BIO_gecheckt']                    = $new_ditjaar_biogecheckt;

//      $params_contact['values']['INTAKE.NAW_status']                      = $new_ditjaar_nawstatus;
//      $params_contact['values']['INTAKE.BIO_status']                      = $new_ditjaar_biostatus;

        if (empty($werving_vakantieregio) AND $vakantieregio) {
            $params_contact['values']['WERVING.vakantieregio']              = $vakantieregio;
        }

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

            $params_contact['values']['DITJAAR.ditjaar_deelnamestatus:label']   = $new_ditjaar_deelnamestatus;

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
/*
            $params_contact['values']['DITJAAR.ditjaar_leeftijd']           = $new_ditjaar_criteria_leeftijd;
            $params_contact['values']['DITJAAR.ditjaar_school']             = $new_ditjaar_criteria_school;
            $params_contact['values']['DITJAAR.ditjaar_criteria_indicatie'] = $new_ditjaar_criteria_indicatie;
            $params_contact['values']['DITJAAR.ditjaar_criteria_oordeel']   = $new_ditjaar_criteria_oordeel;
*/
            wachthond($extdebug,3, 'ditjaar_part_groepklas',            $ditjaar_part_groepklas);
            wachthond($extdebug,3, 'ditjaar_part_voorkeur',             $ditjaar_part_voorkeur);
/*
            wachthond($extdebug,2, 'new_ditjaar_criteria_leeftijd',     $new_ditjaar_criteria_leeftijd);
            wachthond($extdebug,2, 'new_ditjaar_criteria_school',       $new_ditjaar_criteria_school);
            wachthond($extdebug,2, 'new_ditjaar_criteria_indicatie',    $new_ditjaar_criteria_indicatie);
            wachthond($extdebug,2, 'new_ditjaar_criteria_oordeel',      $new_ditjaar_criteria_oordeel);
*/
        }

        ################################################################
        ### DIT JAAR UPDATEN (ALS ER EEN PRIMAIRE REGISTRATIE LEID IS)
        ################################################################

        if ($ditjaar_prim_eventid > 0 AND in_array($ditjaar_prim_event_type_id, $eventtypesleidall)) {

            wachthond($extdebug,2, "########################################################################");
            wachthond($extdebug,1, "### CORE 8.1 e DIT JAAR UPDATEN (IGV EEN PRIMAIRE REGISTRATIE LEID)", "[LEID]");
            wachthond($extdebug,3, "########################################################################");

            // M61: TODO URGENT: DEZE KLOPT NOG NIET
            $params_contact['values']['DITJAAR.ditjaar_kid']                    = $eventkamp_event_id;

            $params_contact['values']['DITJAAR.ditjaar_leeftijd']               = "";
            $params_contact['values']['DITJAAR.ditjaar_school']                 = "";
            $params_contact['values']['DITJAAR.ditjaar_criteria_indicatie']     = "";
            $params_contact['values']['DITJAAR.ditjaar_criteria_indicatie']     = "";

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

        if ($ditevent_eventid == $ditjaar_prim_eventid AND $ditevent_part_id == $ditjaar_prim_partid) {

            if (in_array($ditjaar_prim_event_type_id, $eventtypesall)) {

                wachthond($extdebug,2, "########################################################################");
                wachthond($extdebug,1, "### CORE 8.1 g IF DITEVENT == PRIMAIR EVENTID VAN DIT JAAR",      "[ALL]");
                wachthond($extdebug,3, "########################################################################");

                $params_contact['values']['DITJAAR.ditjaar_regdate']            = $ditevent_register_date; 
                $params_contact['values']['DITJAAR.ditjaar_rol']                = $ditjaar_part_rol;
                $params_contact['values']['DITJAAR.ditjaar_functie']            = $ditjaar_part_functie;
            }

        #####################################################
        ### DIT EVENT & DIT JAAR DEEL
        #####################################################

        if (in_array($ditjaar_prim_event_type_id, $eventtypesdeelall)) {

            wachthond($extdebug,2, "########################################################################");
            wachthond($extdebug,1, "### CORE 8.1 h IF DITEVENT == PRIMAIR EVENTID VAN DIT JAAR", "[EVENTTYPE = DEEL]");
            wachthond($extdebug,3, "########################################################################");

            $params_contact['values']['DITJAAR.ditjaar_leeftijd']               = $new_ditjaar_criteria_leeftijd;
            $params_contact['values']['DITJAAR.ditjaar_school']                 = $new_ditjaar_criteria_school;
            $params_contact['values']['DITJAAR.ditjaar_criteria_indicatie']     = $new_ditjaar_criteria_indicatie;
            $params_contact['values']['DITJAAR.ditjaar_criteria_oordeel']       = $new_ditjaar_criteria_oordeel;

//          M61: de statussen rondom wachtlijst en criteriacheck worden al eerder via API4 weggeschreven naar PART
            $params_contact['values']['DITJAAR.ditjaar_wachtlijst_erop']        = $new_ditevent_wachtlijst_erop;
            $params_contact['values']['DITJAAR.ditjaar_wachtlijst_eraf']        = $new_ditevent_wachtlijst_eraf;
            $params_contact['values']['DITJAAR.ditjaar_criteriacheck_start']    = $new_ditevent_criteriacheck_start;
            $params_contact['values']['DITJAAR.ditjaar_criteriacheck_einde']    = $new_ditevent_criteriacheck_einde;

            wachthond($extdebug,2, 'new_ditjaar_criteria_leeftijd',             $new_ditjaar_criteria_leeftijd);
            wachthond($extdebug,2, 'new_ditjaar_criteria_school',               $new_ditjaar_criteria_school);
            wachthond($extdebug,2, 'new_ditjaar_criteria_indicatie',            $new_ditjaar_criteria_indicatie);
            wachthond($extdebug,2, 'new_ditjaar_criteria_oordeel',              $new_ditjaar_criteria_oordeel);
        }

        #####################################################
        ### DIT EVENT & DIT JAAR DEEL = YES
        #####################################################

        if ($ditjaardeelyes == 1) {

        }

        #####################################################
        ### DIT EVENT & DIT JAAR LEID
        #####################################################

        if ($ditjaarleidyes == 1) {

        }

        #####################################################
        ### DIT EVENT & DIT JAAR DEEL OF DIT JAAR LEID
        #####################################################

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

            $params_contact['values']['DITJAAR.ditjaar_fietshuur']          = $new_cont_fietshuur;
            $params_contact['values']['DITJAAR.ditjaar_regeling']           = $new_kampgeldregeling;

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

    #####################################################
    ### DIT JAAR (INDIEN DITJAAR ZOWEL DEEL ALS LEID)
    #####################################################

    // M61: theoretisch kan er in 1 kalenderjaar zowel een deel als leid event zijn

    if ($ditjaar_all_deel_count == 1 OR $ditjaar_all_leid_count == 1) {

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

        if ($eventkamp_event_start)     {
            $params_part_ditevent['values']['PART.eventjaar']           = $eventkamp_event_start;
        }
        if ($eventkamp_kampjaar)        {
            $params_part_ditevent['values']['PART.kampjaar']            = $eventkamp_kampjaar;
        }
        if ($eventkamp_event_weeknr)    {
            $params_part_ditevent['values']['PART.PART_kampweek_nr']    = $eventkamp_event_weeknr;
        }
        if ($eventkamp_event_start)     {
            $params_part_ditevent['values']['PART.PART_kampstart']      = $eventkamp_event_start;
        }
        if ($eventkamp_event_einde)     {
            $params_part_ditevent['values']['PART.PART_kampeinde']      = $eventkamp_event_einde;
        }

        if ($eventkamp_pleklang)        {
            $params_part_ditevent['values']['PART.kamplocatie']         = $eventkamp_pleklang;
        }
        if ($eventkamp_stadlang)        {
            $params_part_ditevent['values']['PART.kampplaats']          = $eventkamp_stadlang;
        }

        if ($contact_id)                {
            $params_part_ditevent['values']['PART.PART_cid']            = $contact_id;
        }

        if ($ditevent_part_id)              { $params_part_ditevent['values']['PART.PART_pid']              = $ditevent_part_id;            }
        if ($ditevent_part_eventid)         { $params_part_ditevent['values']['PART.PART_eid']              = $ditevent_part_eventid;       }
        if ($eventkamp_event_id)            { $params_part_ditevent['values']['PART.PART_kid']              = $eventkamp_event_id;          }
        if ($ditevent_lineitem_contribid)   { $params_part_ditevent['values']['PART.PART_bid']              = $ditevent_lineitem_contribid; }

        if ($eventkamp_kamptype_naam)       { $params_part_ditevent['values']['PART.PART_kamptype_naam']    = $eventkamp_kamptype_naam;     }
        if ($eventkamp_kamptype_id)         { $params_part_ditevent['values']['PART.PART_kamptype_id']      = $eventkamp_kamptype_id;       }
        if ($eventkamp_kampnaam)            { $params_part_ditevent['values']['PART.PART_kamplang']         = $eventkamp_kampnaam;          }
        if ($eventkamp_kampkort)            { $params_part_ditevent['values']['PART.PART_kampkort']         = $eventkamp_kampkort;          }

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
        }
/*
        if ($new_ditevent_part_status_id)   { $params_part_ditevent['values']['status_id']                  = $new_ditevent_part_status_id; }
*/
        if ($new_ditevent_deelnamestatus)   { $params_part_ditevent['values']['PART.deelnamestatus:label']  = $new_ditevent_deelnamestatus; }

        if ($ditevent_register_date)        { $params_part_ditevent['values']['PART.regdate']               = $ditevent_register_date;      }
        if ($ditevent_part_rol)             { $params_part_ditevent['values']['PART.PART_kamprol']          = $ditevent_part_rol;           }
        if ($ditevent_part_functie)         { $params_part_ditevent['values']['PART.PART_kampfunctie']      = $ditevent_part_functie;       }
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

        if ($toeristenbelasting)    { $params_part_ditevent['values']['PART.PART_Toeristenbelasting']   = $toeristenbelasting;      }
        if ($new_kampgeldregeling)  { $params_part_ditevent['values']['PART_KAMPGELD.regeling']         = $new_kampgeldregeling;    }
        if ($new_part_fietshuur)    { $params_part_ditevent['values']['PART_KAMPGELD.fietshuur']        = $new_part_fietshuur;      }

        if ($contrib_id)            { $params_part_ditevent['values']['PART_KAMPGELD.contribid']        = $contrib_id;  }

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
/*            
            if ($new_ditevent_criteria_leeftijd)  { 
                $params_part_ditevent['values']['PART_DEEL_INTERN.criteria_leeftijd']  = $new_ditevent_criteria_leeftijd;
            }
            if ($new_ditevent_criteria_school)    {
                $params_part_ditevent['values']['PART_DEEL_INTERN.criteria_school']    = $new_ditevent_criteria_school;
            }
            if ($new_ditevent_criteria_indicatie)   {
                $params_part_ditevent['values']['PART_DEEL_INTERN.criteria_indicatie'] = $new_ditevent_criteria_indicatie;
            }
            if ($new_ditevent_criteria_oordeel) {
                $params_part_ditevent['values']['PART_DEEL_INTERN.criteria_oordeel']   = $new_ditevent_criteria_oordeel;
            }
            if ($new_ditevent_wachtlijst_erop) {
                $params_part_ditevent['values']['PART_DEEL_INTERN.wachtlijst_erop']    = $new_ditevent_wachtlijst_erop;
            }
            if ($new_ditevent_wachtlijst_eraf) {
                $params_part_ditevent['values']['PART_DEEL_INTERN.wachtlijst_eraf']    = $new_ditevent_wachtlijst_eraf;
            }
            if ($new_ditevent_criteriacheck_start != NULL) {
                $params_part_ditevent['values']['PART_DEEL_INTERN.criteriacheck_start'] = $new_ditevent_criteriacheck_start;
            }
            if ($new_ditevent_criteriacheck_einde != NULL) {
                $params_part_ditevent['values']['PART_DEEL_INTERN.criteriacheck_einde'] = $new_ditevent_criteriacheck_einde;
            }
  */
        }

        #####################################################
        ### DIT EVENT LEID [YES + MSS + TST]
        #####################################################

        if ($diteventleidyes == 1 OR $diteventleidmss == 1 OR $diteventleidtst == 1) {

        // if ($ditevent_leid_functie)   { $params_part_ditevent['values']['PART_LEID.Functie']    = $ditevent_leid_functie;   }

        // M61: HIER LIEVER GEEN LOGIC MEER MAAR LIEVER ELDERS

        wachthond($extdebug,3, 'new_ditjaar_nawgecheckt',   $new_ditjaar_nawgecheckt);
        wachthond($extdebug,3, 'new_ditpart_nawgecheckt',   $new_ditpart_nawgecheckt);
/*
        $juistejaar = infiscalyear($new_ditjaar_nawgecheckt,$eventkamp_event_start);
        wachthond($extdebug,3, 'juistejaar',                $juistejaar);
        $nawnieuwer = date_bigger($new_ditjaar_nawgecheckt, $new_ditpart_nawgecheckt);
        wachthond($extdebug,3, 'nawnieuwer',                $nawnieuwer);
*/
        if (infiscalyear($new_ditjaar_nawgecheckt,$eventkamp_event_start) == 1 AND date_bigger($new_ditjaar_nawgecheckt, $new_ditpart_nawgecheckt) == 1) {
            $new_ditpart_nawgecheckt = $new_ditjaar_nawgecheckt;  
        }
//      if (infiscalyear($new_ditjaar_nawgecheckt,$eventkamp_event_start) == 1 AND empty($new_ditpart_nawgecheckt)) {
//          $new_ditpart_nawgecheckt = $new_ditjaar_nawgecheckt;  
//      }
        wachthond($extdebug,3, 'new_ditpart_nawgecheckt',   $new_ditpart_nawgecheckt);

        wachthond($extdebug,3, 'new_ditjaar_biogecheckt',   $new_ditjaar_biogecheckt);
        wachthond($extdebug,3, 'new_ditpart_biogecheckt',   $new_ditpart_biogecheckt);
/*
        $juistejaar = infiscalyear($new_ditjaar_biogecheckt,$eventkamp_event_start);
        wachthond($extdebug,3, 'juistejaar',                $juistejaar);
        $bionieuwer = date_bigger($new_ditjaar_biogecheckt, $new_ditpart_biogecheckt);
        wachthond($extdebug,3, 'bionieuwer',                $bionieuwer);
*/
        if (infiscalyear($new_ditjaar_biogecheckt,$eventkamp_event_start) == 1 AND date_bigger($new_ditjaar_biogecheckt, $new_ditpart_biogecheckt) == 1) {
            $new_ditpart_biogecheckt = $new_ditjaar_biogecheckt;  
        }
//      if (infiscalyear($new_ditjaar_biogecheckt,$eventkamp_event_start) == 1 AND empty($new_ditpart_biogecheckt)) {
//          $new_ditpart_biogecheckt = $new_ditjaar_biogecheckt;  
//      }
        wachthond($extdebug,3, 'new_ditpart_biogecheckt',   $new_ditpart_biogecheckt);

        if ($ditevent_part_1stleid)     {
            $params_part_ditevent['values']['PART.PART_1xkeer_leid']    = $ditevent_part_1stleid;
        }
        if ($new_ditpart_nawgecheckt)   {
//          $params_part_ditevent['values']['PART.NAW_gecheckt']        = $new_ditpart_nawgecheckt;
        }
        if ($new_ditpart_biogecheckt)   {
//          $params_part_ditevent['values']['PART.BIO_gecheckt']        = $new_ditpart_biogecheckt;
        }

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

    }
/*
    ##########################################################################################
    # 8.3 UPDATE PARAMS_CONTACT MET STATISTIEKEN
    if ($extdjcont == 123 AND in_array($groupID, $profilecont)) {     // PROFILE CONT + PART (BASIC)
    ##########################################################################################

        wachthond($extdebug,1, "### CORE 8.3 UPDATE PARAMS_CONTACT MET STATS KAMPCV ###", "[groupID: $groupID] [op: $op]");

        wachthond($extdebug,3, "eerstekeer",    $eerste_keer);
        wachthond($extdebug,3, "laatstekeer",   $laatste_keer);

        $params_contact['values']['Curriculum.Eerste_keer']                 = $eerste_keer;
        $params_contact['values']['Curriculum.Laatste_keer']                = $laatste_keer;
        $params_contact['values']['Curriculum.Totaal_keren_mee']            = $totaal_mee;

        if ($exttag == 1) {
          // TODO $params_contact['custom_856']     = $tagcv_deel;
          // TODO $params_contact['custom_848']     = $tagnr_deel;
          // TODO $params_contact['custom_857']     = $tagcv_leid; // M61 URGENT TODO FIX THIS (BECOMES EMPTY SOMETIMES)
          // TODO $params_contact['custom_849']     = $tagnr_leid;
          // TODO $params_contact['custom_850']     = $tagverschildeel;
          // TODO $params_contact['custom_851']     = $tagverschilleid;
        }

        if ($cv_deel) { $params_contact['values']['Curriculum.CV_Deel']     = $cv_deel;     }
        $params_contact['values']['Curriculum.Keren_Deel']                  = $keren_deel;
        $params_contact['values']['Curriculum.Eerste_deel']                 = $eerste_deel;
        $params_contact['values']['Curriculum.Laatste_deel']                = $laatste_deel;
        $params_contact['values']['Curriculum.EventCV_Deel']                = $evtcv_deel;
        $params_contact['values']['Curriculum.EventTotaal_Deel']            = $evtcv_deel_nr;
        $params_contact['values']['Curriculum.Eventverschil_Deel']          = $evtcv_deel_dif;

        $params_contact['values']['Curriculum.Keren_Topkamp']               = $keren_top;
        $params_contact['values']['Curriculum.Eerste_Topkamp']              = $eerste_top;
        $params_contact['values']['Curriculum.Laatste_Topkamp']             = $laatste_top;

        if ($cv_leid) { $params_contact['values']['Curriculum.CV_Leid']     = $cv_leid;     }
        $params_contact['values']['Curriculum.Keren_Leid']                  = $keren_leid;
        $params_contact['values']['Curriculum.Eerste_leid']                 = $eerste_leid;
        $params_contact['values']['Curriculum.Laatste_leid']                = $laatste_leid;
        $params_contact['values']['Curriculum.EventCV_Leid']                = $evtcv_leid;
        $params_contact['values']['Curriculum.EventTotaal_Leid']            = $evtcv_leid_nr;
        $params_contact['values']['Curriculum.Eventverschil_Leid']          = $evtcv_deel_dif;

        $params_contact['values']['Curriculum.CV_deel_text_']               = $cv_deel_text;
        $params_contact['values']['Curriculum.CV_leid_text_']               = $cv_leid_text;

        wachthond($extdebug, 8, 'params_cont_dusver', $params_contact);
      }
*/
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

        wachthond($extdebug,1, "ditevent_event_kampkort", $ditevent_event_kampkort);

        if ($ditevent_event_kampkort == 'kk1')  { $related_hoofdleiding_id = 14197;}
        if ($ditevent_event_kampkort == 'kk2')  { $related_hoofdleiding_id = 14198;}
        if ($ditevent_event_kampkort == 'bk1')  { $related_hoofdleiding_id = 14199;}
        if ($ditevent_event_kampkort == 'bk2')  { $related_hoofdleiding_id = 14200;}
        if ($ditevent_event_kampkort == 'tk1')  { $related_hoofdleiding_id = 14201;}
        if ($ditevent_event_kampkort == 'tk2')  { $related_hoofdleiding_id = 14202;}
        if ($ditevent_event_kampkort == 'jk1')  { $related_hoofdleiding_id = 14203;}
        if ($ditevent_event_kampkort == 'jk2')  { $related_hoofdleiding_id = 14204;}
        if ($ditevent_event_kampkort == 'top')  { $related_hoofdleiding_id = 14205;}

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

    watchdog('civicrm_timing', core_microtimer("EINDE segment 8.X UPDATE PARAMS"), NULL, WATCHDOG_DEBUG);

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
                    "bid: $ditevent_lineitem_contribid \t/ $ditjaar_part_functie $ditjaar_part_kampkort $ditjaar_pos_kampjaar [PREPARED]");
    }
    if ($extwrite == 1 AND !empty($params_contact) AND $contact_id > 0) {

        $params_contact['reload']               = TRUE;
        $params_contact['checkPermissions']     = FALSE;
        $params_contact['debug']                = $apidebug;
        wachthond($extdebug,1,  "contact     DB UPDATE VOOR $displayname",
                    "cid: $contact_id \t/ $ditjaar_part_functie $ditjaar_part_kampkort $ditjaar_pos_kampjaar [PREPARED]");
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

    $params_contrib_where_id       = $params_contrib['where'][0][2];
    $params_contact_where_id       = $params_contact['where'][0][2];
    $params_part_ditevent_where_id = $params_part_ditevent['where'][0][2];

    wachthond($extdebug,2, "params_contrib_where_id",               "BID: $params_contrib_where_id");
    wachthond($extdebug,2, "params_contact_where_id",               "CID: $params_contact_where_id");
    wachthond($extdebug,2, "params_part_ditevent_where_id",         "PID: $params_part_ditevent_where_id");

    wachthond($extdebug,1, core_microtimer("DB Update voorbereid"));

    wachthond($extdebug,1, "########################################################################");

    // M61: WAAROM ALLEEN NA 2016?

    if (is_numeric($params_contrib_where_id)        AND $contrib_id > 0 AND $ditevent_kampjaar > 2016)       {
        $extwrite_contrib       = 1;
        wachthond($extdebug,1, "PARAMS_CONTRIB  HEEFT CONTRIB_ID ($contrib_id)",        "[DO QUERY]");
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
    wachthond($extdebug,1, "### CORE 99. d PERFORM DB UPDATE $displayname", "bid: $ditevent_lineitem_contribid \t/ $ditevent_part_functie $eventkamp_kampkort $eventkamp_kampjaar");
    wachthond($extdebug,1, "########################################################################");

    wachthond($extdebug,3, "extwrite_contrib",                      $extwrite_contrib);
    wachthond($extdebug,3, 'params_contrib',                        $params_contrib);

    watchdog('civicrm_timing', core_microtimer("START PERFORM DB UPDATE CONTRIBUTION"), NULL, WATCHDOG_DEBUG);

    if ($extwrite_contrib == 1) {

//      $result_contrib = civicrm_api4('Contribution', 'update',    $params_contrib);

        wachthond($extdebug,1,  "contrib     DB UPDATE VOOR $displayname",  "[EXECUTED]");
    } else {
        wachthond($extdebug,1,  "contrib     DB UPDATE VOOR $displayname",  "[SKIPPED]");
    }

    watchdog('civicrm_timing', core_microtimer("EINDE PERFORM DB UPDATE CONTRIBUTION"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE 99. e PERFORM DB UPDATE $displayname",     "cid: $contact_id \t/ $ditjaar_part_functie $ditjaar_part_kampkort $ditjaar_kampjaar");
    wachthond($extdebug,1, "########################################################################");

    wachthond($extdebug,4, "extwrite_contact",                          $extwrite_contact);

    watchdog('civicrm_timing', core_microtimer("START PERFORM DB UPDATE CONT"), NULL, WATCHDOG_DEBUG);

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
            civicrm_api4('Contact', 'update', $params_contact);
            $dur_c = number_format(microtime(true) - $start_c, 3);

            wachthond($extdebug, 1, "contact     DB UPDATE VOOR $displayname", ": [EXECUTED] in $dur_c sec");
        } else {
            wachthond($extdebug, 1, "contact     DB UPDATE VOOR $displayname", ": [SKIPPED] - Geen wijzigingen");
        }

    } else {
        wachthond($extdebug, 1, "contact     DB UPDATE VOOR $displayname", ": [SKIPPED] - Geen params");
    }

    watchdog('civicrm_timing', core_microtimer("EINDE PERFORM DB UPDATE CONT"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, "### CORE 99. f PERFORM DB UPDATE $displayname",     "pid: $ditevent_part_id \t/ $ditevent_part_functie $eventkamp_kampkort $eventkamp_kampjaar (eid: $ditevent_part_eventid)");
    wachthond($extdebug,1, "########################################################################");

    wachthond($extdebug,4, "extwrite_part_ditevent",                    $extwrite_part_ditevent);

    watchdog('civicrm_timing', core_microtimer("START PERFORM DB UPDATE PART"), NULL, WATCHDOG_DEBUG);

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
            civicrm_api4('Participant', 'update', $params_part_ditevent);
            $dur_p = number_format(microtime(true) - $start_p, 3);

            wachthond($extdebug, 1, "participant DB UPDATE VOOR $displayname", ": [EXECUTED] in $dur_p sec");
        } else {
            wachthond($extdebug, 1, "participant DB UPDATE VOOR $displayname", ": [SKIPPED] - Geen wijzigingen");
        }

    } else {
        wachthond($extdebug, 1, "participant DB UPDATE VOOR $displayname", ": [SKIPPED] - Geen params of ID");
    }

    watchdog('civicrm_timing', core_microtimer("EINDE PERFORM DB UPDATE PART"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,9, "########################################################################");
    wachthond($extdebug,9, "### CORE 99. f FINAL DATABASE QUERY",                    "[SHOWRERSULTS]");
    wachthond($extdebug,9, "########################################################################");

    if ($extwrite == 1 AND !empty($params_contrib) AND $ditevent_lineitem_contribid > 0 AND $ditevent_kampjaar > 2016) {
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

/**
 * Helper om de verstreken tijd sinds de vorige aanroep bij te houden.
 */
function core_microtimer($label) {
    static $start_time = null;
    static $last_time = null;
    $now = microtime(true);
    
    // Eerste keer aanroepen: zet beide timers op nu
    if ($start_time === null) {
        $start_time = $now;
        $last_time = $now;
        return "Timer gestart: $label";
    }
    
    $diff_last = round($now - $last_time, 3); // Tijd sinds vorige aanroep
    $diff_total = round($now - $start_time, 3); // Tijd sinds start
    
    $last_time = $now;

    // Output: [Totaal: 16.05s] [Stap: 0.02s] tot EINDE bepaal CV
    return "[Totaal: {$diff_total}s] [Stap: {$diff_last}s] tot $label";
}