<?php

declare(strict_types=1);

namespace App\Knowledge\BuildCode;

use App\Knowledge\MakeName;

final class PsaBuildCodeDecoder implements BuildCodeDecoder
{
    private const array MAKES = ['PEUGEOT', 'CITROEN', 'DS'];

    private const string EPOCH = '1976-11-08';

    private const int MAX_OPR = 99999;

    private const array PLANTS = [
        '88' => 'Mulhouse',
        '89' => 'Sochaux',
        '83' => 'Ryton',
        '81' => 'Poissy',
        '78' => 'Porto Real',
        '82' => 'Madrid',
        '84' => 'Dijon',
        '87' => 'Lille',
    ];

    private const array CAVEATS = [
        'La date est celle du compteur de jours de production PSA (1976-11-08 + OPR jours) ; un intervenant du forum Peugeot soutient qu\'il s\'agit de la date de commande et non de fabrication, à quelques jours près.',
        'À utiliser pour trancher une rupture de catalogue (« jusqu\'à / à partir du n° d\'organisation ») ; en cas de date très proche du seuil, vérifier auprès d\'un concessionnaire avec le VIN.',
    ];

    private const array SOURCES = [
        'https://www.forum-peugeot.com/Forum/threads/que-signifie-le-opr-le-dam-et-le-apv-pr-dun-v%C3%A9hicule.7579/',
        'https://www.forum-peugeot.com/Forum/threads/d%C3%A9coder-un-num%C3%A9ro-de-chassis.48207/latest',
        'https://www.forum-peugeot.com/Forum/threads/num%C3%A9ro-opr.71837/latest',
        'https://www.forum-peugeot.com/Forum/threads/num%C3%A9ro-dorganisation-svp.52633/latest',
        'https://www.forum-peugeot.com/Forum/threads/date-de-fabrication-de-la-voiture.77502/latest',
        'https://www.forum-peugeot.com/Forum/threads/date-de-fabrication-de-ma-307.55611/latest',
        'https://www.forum-peugeot.com/Forum/threads/code-opr-%C3%A0-partir-du-vin.130430/',
    ];

    public function supports(string $make): bool
    {
        return \in_array(MakeName::normalize($make), self::MAKES, true);
    }

    public function decode(string $code): DecodedBuildCode
    {
        $compact = strtoupper(preg_replace('/\s+/', '', $code) ?? '');
        if ('' === $compact) {
            throw new InvalidBuildCode('Empty build code.');
        }

        if (1 === preg_match('/^(\d{4,5})$/', $compact, $m)) {
            return $this->build('opr', $m[1]);
        }
        if (1 === preg_match('/^(\d{4,5})([A-Z0-9]{2})$/', $compact, $m)) {
            return $this->build('dam', $m[1], $m[2]);
        }
        if (1 === preg_match('/^(\d{4,5})([A-Z0-9]{2})([A-Z0-9]{5})$/', $compact, $m)) {
            return $this->build('apv', $m[1], $m[2], $m[3]);
        }

        throw new InvalidBuildCode(\sprintf(
            'Unrecognised PSA build code "%s". Expected an OPR (4-5 digits), a DAM (OPR + 2-char plant, 6-7 chars) or an APV/PR (DAM + 5 chars, 11-12 chars).',
            trim($code),
        ));
    }

    public function fromDate(\DateTimeImmutable $date): DecodedBuildCode
    {
        $epoch = new \DateTimeImmutable(self::EPOCH);
        $days = (int) $epoch->setTime(0, 0)->diff($date->setTime(0, 0))->format('%R%a');

        if ($days < 1 || $days > self::MAX_OPR) {
            throw new InvalidBuildCode(\sprintf(
                'The date %s is outside the OPR counter (from %s, 5 digits).',
                $date->format('Y-m-d'),
                $epoch->modify('+1 day')->format('Y-m-d'),
            ));
        }

        return new DecodedBuildCode(
            kind: 'date',
            organisationNumber: str_pad((string) $days, 5, '0', \STR_PAD_LEFT),
            date: $date->setTime(0, 0),
            caveats: self::CAVEATS,
            sources: self::SOURCES,
        );
    }

    private function build(string $kind, string $opr, ?string $plant = null, ?string $sequence = null): DecodedBuildCode
    {
        $days = (int) $opr;
        if ($days < 1) {
            throw new InvalidBuildCode('The organisation number must be at least 1.');
        }

        $plantCode = null !== $plant && ctype_digit($plant) ? $plant : null;

        return new DecodedBuildCode(
            kind: $kind,
            organisationNumber: str_pad($opr, 5, '0', \STR_PAD_LEFT),
            date: (new \DateTimeImmutable(self::EPOCH))->modify(\sprintf('+%d days', $days)),
            plantCode: $plantCode,
            plantName: null !== $plantCode ? (self::PLANTS[$plantCode] ?? null) : null,
            sequence: $sequence,
            caveats: self::CAVEATS,
            sources: self::SOURCES,
        );
    }
}
