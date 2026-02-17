<?php

namespace Drupal\gc_upload\Service;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

class GcUploadPostScorePayloadBuilder {

  protected ?LoggerChannelInterface $logger = NULL;

  public function __construct(LoggerChannelFactoryInterface $loggerFactory = NULL) {
    if ($loggerFactory) {
      $this->logger = $loggerFactory->get('gc_upload');
    }
  }

  protected function log(string $message, array $context = []): void {
    if ($this->logger) {
      $this->logger->notice($message, $context);
    }
    else {
      \Drupal::logger('gc_upload')->notice($message, $context);
    }
  }

  public function build(FormStateInterface $form_state): array {
    $values = $form_state->getValues();

    $individualId = isset($values['gc_id']) ? (int) $values['gc_id'] : 0;
    $facilityId = isset($values['gc_facility_id']) ? (int) $values['gc_facility_id'] : 0;
    $courseId = isset($values['gc_course_id']) ? (int) $values['gc_course_id'] : 0;
    $teeId = isset($values['gc_tee_id']) ? (int) $values['gc_tee_id'] : 0;

    $played_date = (string) ($values['played_date'] ?? $values['scorecard_date'] ?? '');
    $date_iso = $this->dateToIsoNoMs($played_date);

    $holes_mode = (string) ($values['holes_mode'] ?? '18');
    $holesPlayed = $this->holesModeToGcEnum($holes_mode);

    $format = (string) ($values['format'] ?? 'stroke');
    $formatPlayed = ($format === 'match') ? 'MatchPlay' : 'StrokePlay';

    $isTournament = !empty($values['tournament_score']);
    $isPlayedAlone = ((string) ($values['played_alone'] ?? 'no')) === 'yes';

    $attestor = trim((string) ($values['attestor'] ?? ''));
    $attestor = ($attestor === '') ? NULL : $attestor;

    // Extract scorecard stats from inputs.
    $gross_by_hole = $this->extractGrossByHoleFromFormValues($values);
    $putts_by_hole = $this->extractPuttsByHoleFromFormValues($values);

    $fir_by_hole = $this->extractFirByHoleFromFormValues($values);

    // IMPORTANT: must be INT(1) or NULL, not boolean.
    $updown_by_hole = $this->extractUpDownByHoleFromFormValues($values);
    $sand_by_hole = $this->extractSandSaveByHoleFromFormValues($values);

    $pen_by_hole = $this->extractPenaltyByHoleFromFormValues($values);

    // Build holeScores[] exactly like GC expects.
    $holeScores = [];
    for ($h = 1; $h <= 18; $h++) {
      $holeScores[] = [
        'number' => (float) $h,
        'gross' => $gross_by_hole[$h] ?? NULL,
        'putts' => $putts_by_hole[$h] ?? NULL,
        'puttLength' => NULL,
        'club' => NULL,
        'drive' => NULL,
        'fir' => $fir_by_hole[$h] ?? NULL,
        'upDown' => $updown_by_hole[$h] ?? NULL,     // 1 or NULL
        'sandSave' => $sand_by_hole[$h] ?? NULL,     // 1 or NULL
        'penalty' => $pen_by_hole[$h] ?? NULL,
        'max' => NULL,
      ];
    }

    $esc = $this->sumGross($gross_by_hole);

    // If you’re sending any of these fields, GC considers stats tracking enabled.
    $isTrackingStats = TRUE;

    return [
      'id' => NULL,
      'individualId' => $individualId ?: NULL,
      'date' => $date_iso,
      'courseId' => $courseId ?: NULL,
      'teeId' => $teeId ?: NULL,
      'holesPlayed' => $holesPlayed,
      'formatPlayed' => $formatPlayed,
      'esc' => $esc,
      'holeScores' => $holeScores,
      'isHoleByHole' => TRUE,
      'isHoleByHoleRequired' => FALSE,
      'isTrackingStats' => (bool) $isTrackingStats,
      'isTournament' => (bool) $isTournament,
      'isPenalty' => FALSE,
      'attestor' => $attestor,
      'isPlayedAlone' => (bool) $isPlayedAlone,
      'facilityId' => $facilityId ?: NULL,
    ];
  }

  protected function extractGrossByHoleFromFormValues(array $values): array {
    $scores_table = $this->getScoresTableFromAnyInput($values);
    $gross_by_hole = [];

    if (is_array($scores_table)) {
      if (!empty($scores_table['front']['score']) && is_array($scores_table['front']['score'])) {
        foreach ($scores_table['front']['score'] as $k => $v) {
          if (!is_numeric($k)) continue;
          $hole = (int) $k;
          if ($hole < 1 || $hole > 9) continue;
          $gross_by_hole[$hole] = $this->toNullableInt($v);
        }
      }

      if (!empty($scores_table['back']['score']) && is_array($scores_table['back']['score'])) {
        foreach ($scores_table['back']['score'] as $k => $v) {
          if (!is_numeric($k)) continue;
          $idx = (int) $k;
          if ($idx < 1 || $idx > 9) continue;
          $hole = $idx + 9;
          $gross_by_hole[$hole] = $this->toNullableInt($v);
        }
      }
    }

    return $gross_by_hole;
  }

  protected function extractPuttsByHoleFromFormValues(array $values): array {
    $scores_table = $this->getScoresTableFromAnyInput($values);
    $putts_by_hole = [];

    if (is_array($scores_table)) {
      if (!empty($scores_table['front']['putts']) && is_array($scores_table['front']['putts'])) {
        foreach ($scores_table['front']['putts'] as $k => $v) {
          if (!is_numeric($k)) continue;
          $hole = (int) $k;
          if ($hole < 1 || $hole > 9) continue;
          $putts_by_hole[$hole] = $this->toNullableInt($v);
        }
      }

      if (!empty($scores_table['back']['putts']) && is_array($scores_table['back']['putts'])) {
        foreach ($scores_table['back']['putts'] as $k => $v) {
          if (!is_numeric($k)) continue;
          $idx = (int) $k;
          if ($idx < 1 || $idx > 9) continue;
          $hole = $idx + 9;
          $putts_by_hole[$hole] = $this->toNullableInt($v);
        }
      }
    }

    return $putts_by_hole;
  }

  protected function extractFirByHoleFromFormValues(array $values): array {
    $scores_table = $this->getScoresTableFromAnyInput($values);
    $out = [];

    if (is_array($scores_table)) {
      if (!empty($scores_table['front']['fir']) && is_array($scores_table['front']['fir'])) {
        foreach ($scores_table['front']['fir'] as $k => $v) {
          if (!is_numeric($k)) continue;
          $hole = (int) $k;
          if ($hole < 1 || $hole > 9) continue;
          $enum = $this->sanitizeFirEnum($v);
          if ($enum !== NULL) {
            $out[$hole] = $enum;
          }
        }
      }

      if (!empty($scores_table['back']['fir']) && is_array($scores_table['back']['fir'])) {
        foreach ($scores_table['back']['fir'] as $k => $v) {
          if (!is_numeric($k)) continue;
          $idx = (int) $k;
          if ($idx < 1 || $idx > 9) continue;
          $hole = $idx + 9;
          $enum = $this->sanitizeFirEnum($v);
          if ($enum !== NULL) {
            $out[$hole] = $enum;
          }
        }
      }
    }

    return $out;
  }

  /**
   * IMPORTANT: GC expects numeric (1) not boolean.
   */
  protected function extractUpDownByHoleFromFormValues(array $values): array {
    $scores_table = $this->getScoresTableFromAnyInput($values);
    $out = [];

    if (is_array($scores_table)) {
      if (!empty($scores_table['front']['updown']) && is_array($scores_table['front']['updown'])) {
        foreach ($scores_table['front']['updown'] as $k => $v) {
          if (!is_numeric($k)) continue;
          $hole = (int) $k;
          if ($hole < 1 || $hole > 9) continue;
          $n = $this->toCheckedInt1OrNull($v);
          if ($n !== NULL) {
            $out[$hole] = $n;
          }
        }
      }

      if (!empty($scores_table['back']['updown']) && is_array($scores_table['back']['updown'])) {
        foreach ($scores_table['back']['updown'] as $k => $v) {
          if (!is_numeric($k)) continue;
          $idx = (int) $k;
          if ($idx < 1 || $idx > 9) continue;
          $hole = $idx + 9;
          $n = $this->toCheckedInt1OrNull($v);
          if ($n !== NULL) {
            $out[$hole] = $n;
          }
        }
      }
    }

    return $out;
  }

  /**
   * IMPORTANT: GC expects numeric (1) not boolean.
   */
  protected function extractSandSaveByHoleFromFormValues(array $values): array {
    $scores_table = $this->getScoresTableFromAnyInput($values);
    $out = [];

    if (is_array($scores_table)) {
      if (!empty($scores_table['front']['sandsave']) && is_array($scores_table['front']['sandsave'])) {
        foreach ($scores_table['front']['sandsave'] as $k => $v) {
          if (!is_numeric($k)) continue;
          $hole = (int) $k;
          if ($hole < 1 || $hole > 9) continue;
          $n = $this->toCheckedInt1OrNull($v);
          if ($n !== NULL) {
            $out[$hole] = $n;
          }
        }
      }

      if (!empty($scores_table['back']['sandsave']) && is_array($scores_table['back']['sandsave'])) {
        foreach ($scores_table['back']['sandsave'] as $k => $v) {
          if (!is_numeric($k)) continue;
          $idx = (int) $k;
          if ($idx < 1 || $idx > 9) continue;
          $hole = $idx + 9;
          $n = $this->toCheckedInt1OrNull($v);
          if ($n !== NULL) {
            $out[$hole] = $n;
          }
        }
      }
    }

    return $out;
  }

  protected function extractPenaltyByHoleFromFormValues(array $values): array {
    $scores_table = $this->getScoresTableFromAnyInput($values);
    $out = [];

    if (is_array($scores_table)) {
      if (!empty($scores_table['front']['penalty']) && is_array($scores_table['front']['penalty'])) {
        foreach ($scores_table['front']['penalty'] as $k => $v) {
          if (!is_numeric($k)) continue;
          $hole = (int) $k;
          if ($hole < 1 || $hole > 9) continue;
          $n = $this->toNullableInt($v);
          if ($n !== NULL) {
            $out[$hole] = $n;
          }
        }
      }

      if (!empty($scores_table['back']['penalty']) && is_array($scores_table['back']['penalty'])) {
        foreach ($scores_table['back']['penalty'] as $k => $v) {
          if (!is_numeric($k)) continue;
          $idx = (int) $k;
          if ($idx < 1 || $idx > 9) continue;
          $hole = $idx + 9;
          $n = $this->toNullableInt($v);
          if ($n !== NULL) {
            $out[$hole] = $n;
          }
        }
      }
    }

    return $out;
  }

  protected function getScoresTableFromAnyInput(array $values): ?array {
    $scores_table = $this->findScoresTable($values);
    if (is_array($scores_table)) {
      return $scores_table;
    }

    $input = [];
    try {
      $input = \Drupal::request()->request->all();
    }
    catch (\Throwable $e) {
      $input = [];
    }

    $scores_table = $this->findScoresTable($input);
    return is_array($scores_table) ? $scores_table : NULL;
  }

  protected function findScoresTable($data): ?array {
    if (!is_array($data)) {
      return NULL;
    }

    if (isset($data['scores_table']) && is_array($data['scores_table'])) {
      if ($this->looksLikeScoresTable($data['scores_table'])) {
        return $data['scores_table'];
      }
    }

    if ($this->looksLikeScoresTable($data)) {
      return $data;
    }

    foreach ($data as $v) {
      if (is_array($v)) {
        $found = $this->findScoresTable($v);
        if (is_array($found)) {
          return $found;
        }
      }
    }

    return NULL;
  }

  protected function looksLikeScoresTable(array $t): bool {
    if (!isset($t['front']) || !isset($t['back'])) return FALSE;
    if (!is_array($t['front']) || !is_array($t['back'])) return FALSE;

    foreach (['score', 'putts', 'fir', 'updown', 'sandsave', 'penalty'] as $key) {
      if (isset($t['front'][$key]) && is_array($t['front'][$key])) return TRUE;
      if (isset($t['back'][$key]) && is_array($t['back'][$key])) return TRUE;
    }

    return FALSE;
  }

  protected function sanitizeFirEnum($value): ?string {
    $s = trim((string) $value);
    if ($s === '') return NULL;

    // Add MissedUnspecified because GC payload shows it.
    $allowed = [
      'Hit',
      'MissedRight',
      'MissedLeft',
      'MissedShort',
      'MissedLong',
      'MissedUnspecified',
    ];

    return in_array($s, $allowed, TRUE) ? $s : NULL;
  }

  /**
   * Return 1 if checked, else NULL.
   * Drupal checkboxes often come through as 1/"1"/true/"on".
   */
  protected function toCheckedInt1OrNull($value): ?int {
    if ($value === TRUE) return 1;
    if ($value === 1) return 1;
    if ($value === '1') return 1;

    $s = trim((string) $value);
    if ($s === '') return NULL;
    if (strtolower($s) === 'true') return 1;
    if (strtolower($s) === 'on') return 1;

    return NULL;
  }

  protected function sumGross(array $gross_by_hole): ?int {
    $sum = 0;
    $count = 0;

    foreach ($gross_by_hole as $v) {
      if (is_numeric($v)) {
        $sum += (int) $v;
        $count++;
      }
    }

    return $count > 0 ? $sum : NULL;
  }

  protected function toNullableInt($value): ?int {
    if ($value === NULL) return NULL;
    $s = trim((string) $value);
    if ($s === '') return NULL;
    if (!is_numeric($s)) return NULL;
    return (int) $s;
  }

  protected function holesModeToGcEnum(string $holes_mode): string {
    if ($holes_mode === 'front9') return 'FrontNine';
    if ($holes_mode === 'back9') return 'BackNine';
    return 'EighteenHoles';
  }

  protected function dateToIsoNoMs(string $date): string {
    $date = trim($date);
    if ($date === '') return '';
    // Match GC sample: "YYYY-MM-DDT00:00:00"
    return $date . 'T00:00:00';
  }

}
