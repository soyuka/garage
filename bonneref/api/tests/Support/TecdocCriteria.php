<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Catalog\ArticleCriterion;

/**
 * Criteria as TecdocCatalog reads them: the French name of a real TecDoc criterion with its id and unit (read off
 * `criterion_text` of the dev slice), so a test can spell a criterion by the name it knows.
 */
final class TecdocCriteria
{
    /** @var array<string, array{int, string}> French name => criterion id, unit */
    private const array KNOWN = [
        'Année à partir de' => [20, ''],
        "Année jusqu'à" => [21, ''],
        'Code moteur' => [33, ''],
        'Nº de châssis à partir de' => [25, ''],
        'Nº de série' => [749, ''],
        "Nº d'organisation à partir de" => [45, ''],
        "Nº d'organisation jusqu'à" => [46, ''],
        'numéro TECDOC du moteur' => [14, ''],
        "Référence de l'outil spécialisé recommandé" => [1361, ''],
        'uniquement en connexion avec' => [427, ''],
        "Côté d'assemblage" => [100, ''],
        'Type de filtre' => [826, ''],
        'Couleur' => [436, ''],
        'Filetage' => [289, ''],
        'Taraudage/Filetage 1' => [991, ''],
        'Hauteur [mm]' => [209, 'mm'],
        'Diamètre [mm]' => [200, 'mm'],
        'Diamètre extérieur [mm]' => [497, 'mm'],
        'Diamètre extérieur 2 [mm]' => [979, 'mm'],
        'Diamètre intérieur 2 [mm]' => [996, 'mm'],
        'Diamètre de la bague détanchéité [mm]' => [297, 'mm'],
        'Diamètre intérieur [mm]' => [496, 'mm'],
        'Diamètre 3 [mm]' => [343, 'mm'],
        'Diamètre 4 [mm]' => [344, 'mm'],
        'Longueur [mm]' => [203, 'mm'],
        'Largeur [mm]' => [206, 'mm'],
        'Poids [kg]' => [212, 'kg'],
        'Poids net [g]' => [9011, 'g'],
        'Couple de serrage [Nm]' => [524, 'Nm'],
        "Pression d'ouverture clapet de dérivation [bar]" => [1069, 'bar'],
        "Longueur de l'emballage [cm]" => [1620, 'cm'],
        "Hauteur de l'emballage [cm]" => [1622, 'cm'],
    ];

    public static function of(string $name, string $value): ArticleCriterion
    {
        [$id, $unit] = self::KNOWN[$name] ?? throw new \LogicException(\sprintf('"%s" is not a TecDoc criterion known to the tests: add it to %s.', $name, self::class));

        return new ArticleCriterion($name, $value, $id, '' === $unit ? null : $unit, $name, $value);
    }

    /** @var array<string, string> a partsapi spelling => the TecDoc name of the same criterion */
    private const array PARTSAPI = [
        'Numéro de châssis (VIN) à partir de' => 'Nº de châssis à partir de',
        "Diamètre extérieur de la bague d'étanchéité [" => 'Diamètre extérieur 2 [mm]',
        "Diamètre intérieur de la bague d'étanchéité [" => 'Diamètre intérieur 2 [mm]',
        "Pression d'ouverture clapet de dérivation [ba" => "Pression d'ouverture clapet de dérivation [bar]",
        'Taraudage/Filetage 1' => 'Taraudage/Filetage 1',
    ];

    /**
     * The criteria of a partsapi fixture (names cut at 45 characters, no ids), as TecDoc holds them: a name with no
     * TecDoc criterion (« pour le modèle de la série de modèles », « Type de véhicule », « Règlement européen… ») is left out.
     *
     * @param array<array-key, mixed> $rows
     *
     * @return list<ArticleCriterion>
     */
    public static function fromPartsapiRows(array $rows): array
    {
        $criteria = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['CRITERIA_NAME'] ?? ''));
            $name = self::PARTSAPI[$name] ?? $name;
            if (isset(self::KNOWN[$name])) {
                $criteria[] = self::of($name, trim((string) ($row['CRITERIA_VALUE'] ?? '')));
            }
        }

        return $criteria;
    }
}
