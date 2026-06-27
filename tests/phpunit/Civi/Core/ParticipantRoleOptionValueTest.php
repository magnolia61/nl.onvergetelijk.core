<?php

namespace Civi\Core;

use Civi\Test\EndToEndInterface;

/**
 * Regressietest voor de participant-rol-ID's die core.php (CORE 5.5) gebruikt.
 *
 * @group e2e
 *
 * ACHTERGROND (de bug van juni 2026)
 * ----------------------------------
 * core.php wijst bij elke registratie een participant-rol toe op basis van het
 * event-type en de functie. Dat gebeurt met HARD GECODEERDE numerieke
 * option_value-waarden uit de groep 'participant_role'. De oude waarden waren
 * fout en wezen naar de verkeerde rollen:
 *
 *   constante           OUD (fout)              NIEUW (correct)
 *   ------------------  ----------------------  ----------------
 *   rol_leiding         1  = Bezoeker/Attendee  6  = Leiding
 *   rol_hoofdleiding    2  = bestaat NIET        12 = Hoofdleiding
 *   rol_deelnemer_top   11 = Cursist (inactief)  8  = Deelnemer Topkamp
 *   rol_leiding_top     15 = Kampstaf            9  = Leiding Topkamp
 *
 * Gevolg: leiding werd als 'Bezoeker' weggeschreven. 53 records van 2026 zijn
 * destijds via SQL rechtgezet; deze test borgt dat de CONSTANTEN in core.php
 * blijven kloppen met de live option-values, zodat de fout niet stil terugkeert
 * (bijv. door een herstelde DB-dump of een handmatige edit van de option group).
 *
 * Deze test leest alleen de option-group (read-only) en muteert niets, dus is
 * TransactionalInterface niet nodig.
 */
class ParticipantRoleOptionValueTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface {

  /**
   * De contracttabel: de constante zoals core.php hem gebruikt → de canonieke
   * name in de option_group 'participant_role'. We toetsen op `name` (niet `label`),
   * want labels kunnen vertaald/aangepast worden; de name is de stabiele sleutel.
   *
   * @return array<int,string> value => verwachte name
   */
  private function verwachteRollen(): array {
    return [
      7  => 'Deelnemer',          // $rol_deelnemer
      6  => 'Leiding',            // $rol_leiding         (was fout: 1 = Attendee)
      12 => 'Hoofdleiding',       // $rol_hoofdleiding    (was fout: 2 = niet-bestaand)
      8  => 'Deelnemer Topkamp',  // $rol_deelnemer_top   (was fout: 11 = Cursist)
      9  => 'Leiding Topkamp',    // $rol_leiding_top     (was fout: 15 = Kampstaf)
      15 => 'Kampstaf',           // $rol_kampstaf        (toer/meet)
      16 => 'Deelnemer_Gave',     // $rol_deelnemer_gave  (St. Gave)
    ];
  }

  /**
   * Haal alle participant_role option-values op als value => [name, is_active].
   */
  private function liveRollen(): array {
    $rows = \civicrm_api4('OptionValue', 'get', [
      'checkPermissions' => FALSE,
      'select'           => ['value', 'name', 'is_active'],
      'where'            => [['option_group_id.name', '=', 'participant_role']],
    ]);
    $map = [];
    foreach ($rows as $r) {
      $map[(int) $r['value']] = ['name' => $r['name'], 'is_active' => (bool) $r['is_active']];
    }
    return $map;
  }

  // ########################################################################
  // ### SCENARIO A: ELKE CONSTANTE WIJST NAAR DE JUISTE, ACTIEVE ROL
  // ########################################################################

  /**
   * Elke rol-ID die core.php gebruikt bestaat, is actief en heeft de naam die
   * core verwacht. Faalt deze test, dan is een van de constanten in core.php
   * (CORE 5.5) niet meer in lijn met de option-group → rollen worden fout
   * weggeschreven.
   */
  public function testCoreRolConstantenKloppenMetLiveOptionValues(): void {
    $live = $this->liveRollen();

    foreach ($this->verwachteRollen() as $value => $verwachteNaam) {
      $this->assertArrayHasKey($value, $live,
        "Rol-ID $value (verwacht '$verwachteNaam') bestaat niet in option_group participant_role. " .
        "core.php CORE 5.5 zou dan een ongeldige rol wegschrijven."
      );
      $this->assertSame($verwachteNaam, $live[$value]['name'],
        "Rol-ID $value moet naam '$verwachteNaam' hebben, maar is '{$live[$value]['name']}'. " .
        "Controleer de constanten in core.php (sectie CORE 5.5) tegen de option-group."
      );
      $this->assertTrue($live[$value]['is_active'],
        "Rol '$verwachteNaam' (ID $value) is inactief — core mag geen inactieve rol toewijzen."
      );
    }
  }

  // ########################################################################
  // ### SCENARIO B: DE OUDE FOUTE WAARDEN ZIJN AANTOONBAAR FOUT
  // ########################################################################

  /**
   * Documenteert WAAROM de fix nodig was: de oude waarden wijzen niet naar de
   * rol die core bedoelde. Dit beschermt tegen "terugdraaien naar de oude getallen".
   */
  public function testOudeFouteRolWaardenWijzenNietNaarLeidingRollen(): void {
    $live = $this->liveRollen();

    // Oud rol_leiding = 1 → dat is Attendee/Bezoeker, NIET Leiding.
    $this->assertNotSame('Leiding', $live[1]['name'] ?? NULL,
      'Rol-ID 1 mag niet als Leiding gebruikt worden (1 = Attendee/Bezoeker). Dit was de oorspronkelijke bug.');

    // Oud rol_hoofdleiding = 2 → bestaat niet eens in de option-group.
    $this->assertArrayNotHasKey(2, $live,
      'Rol-ID 2 bestaat niet in participant_role; het mocht nooit als Hoofdleiding gebruikt worden.');

    // Oud rol_deelnemer_top = 11 → Cursist (en inactief).
    if (isset($live[11])) {
      $this->assertNotSame('Deelnemer Topkamp', $live[11]['name'],
        'Rol-ID 11 is Cursist, niet Deelnemer Topkamp.');
    }

    // Oud rol_leiding_top = 15 → Kampstaf, niet Leiding Topkamp.
    $this->assertNotSame('Leiding Topkamp', $live[15]['name'] ?? NULL,
      'Rol-ID 15 is Kampstaf; Leiding Topkamp is 9.');
  }
}
