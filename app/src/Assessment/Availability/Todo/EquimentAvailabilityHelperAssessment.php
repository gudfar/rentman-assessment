<?php
namespace Assessment\Availability\Todo;

use Assessment\Availability\EquimentAvailabilityHelper;
use DateTime;
use PDO;

class EquimentAvailabilityHelperAssessment extends EquimentAvailabilityHelper {

	/**
	 * This function checks if a given quantity is available in the passed time frame
	 * @param int      $equipment_id Id of the equipment item
	 * @param int      $quantity How much should be available
	 * @param DateTime $start Start of time window
	 * @param DateTime $end End of time window
	 * @return bool True if available, false otherwise
	 */
	public function isAvailable(int $equipment_id, int $quantity, DateTime $start, DateTime $end) : bool {

        $stmt = $this->getDatabaseConnection()->prepare(<<<SQL
			SELECT e.stock, p.start, p.end, p.quantity
			FROM equipment e
			LEFT JOIN planning p
				ON p.equipment = e.id
				AND p.start < :end
				AND p.end > :start
			WHERE e.id = :equipment_id
			ORDER BY p.start ASC, p.end ASC
		SQL);

        $stmt->execute([
			':equipment_id' => $equipment_id,
			':start'        => $start->format('Y-m-d H:i:s'),
			':end'          => $end->format('Y-m-d H:i:s'),
		]);

        $planningRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($planningRows)) {
			return false;
		}

		$stock = (int)$planningRows[0]['stock'];
        if ($planningRows[0]['start'] === null) {
			return $quantity <= $stock;
		}

		$peakLoad = $this->calculatePeakConcurrentLoad($planningRows);

		return ($peakLoad + $quantity) <= $stock;
	}

	/**
	 * Calculate all items that are short in the given period
	 * @param DateTime $start Start of time window
	 * @param DateTime $end End of time window
	 * @return array Key/value array with equipment ids as keys and negative shortage values
	 */
	public function getShortages(DateTime $start, DateTime $end) : array {

        $stmt = $this->getDatabaseConnection()->prepare(<<<SQL
			SELECT e.id AS equipment_id, e.stock, p.start, p.end, p.quantity
			FROM equipment e
			INNER JOIN planning p
				ON p.equipment = e.id
				AND p.start < :end
				AND p.end > :start
			ORDER BY e.id ASC, p.start ASC, p.end ASC
		SQL);

		$stmt->execute([
			':start' => $start->format('Y-m-d H:i:s'),
			':end'   => $end->format('Y-m-d H:i:s'),
		]);
		$planningRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$rowsByEquipment = [];
		foreach ($planningRows as $row) {
			$rowsByEquipment[$row['equipment_id']][] = $row;
		}

		$shortages = [];
		foreach ($rowsByEquipment as $equipmentId => $rows) {
			$stock    = (int)$rows[0]['stock'];
			$peakLoad = $this->calculatePeakConcurrentLoad($rows);

			if ($peakLoad > $stock) {
				$shortages[$equipmentId] = $stock - $peakLoad; // negative value
			}
		}

		return $shortages;
	}

	/**
	 * Calculate the peak concurrent load for a set of planning rows using the sweep line algorithm.
	 *
	 * Each planning entry adds load at its start time and removes it at its end time.
	 * By sorting all these events and walking through them, we find the highest load
	 * that occurs at any single moment — without needing to check every point in time.
	 *
	 * @param  array $planningRows Rows from the planning table with 'start', 'end', 'quantity'
	 * @return int   The maximum quantity in use at any single moment
	 */
	private function calculatePeakConcurrentLoad(array $planningRows) : int {

        $events = [];
		foreach ($planningRows as $row) {
			$events[] = [$row['start'], (int)$row['quantity']];
			$events[] = [$row['end'],  -(int)$row['quantity']];
		}

		usort($events, function($a, $b) {
			if ($a[0] === $b[0]) return $a[1] - $b[1];
			return $a[0] <=> $b[0];
		});

		$currentLoad = 0;
		$peakLoad    = 0;
		foreach ($events as [, $delta]) {
			$currentLoad += $delta;
			if ($currentLoad > $peakLoad) {
				$peakLoad = $currentLoad;
			}
		}

		return $peakLoad;
	}

}
