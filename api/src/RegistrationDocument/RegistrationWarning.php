<?php

declare(strict_types=1);

namespace App\RegistrationDocument;

enum RegistrationWarning: string
{
    case PlateFormat = 'plate_format';
    case VinInvalid = 'vin_invalid';
    case DateUnreadable = 'date_unreadable';
    case Blurry = 'blurry';
    case Cropped = 'cropped';
    case Dark = 'dark';
    case Glare = 'glare';
    case PlateDoubtful = 'plate_doubtful';
    case VinDoubtful = 'vin_doubtful';
    case DateDoubtful = 'date_doubtful';
    case Incomplete = 'incomplete';

    /**
     * The codes the vision model may report: the others are raised by the server's own checks.
     *
     * @return list<string>
     */
    public static function modelCodes(): array
    {
        return [self::Blurry->value, self::Cropped->value, self::Dark->value, self::Glare->value, self::PlateDoubtful->value, self::VinDoubtful->value, self::DateDoubtful->value];
    }

    public function message(): string
    {
        return match ($this) {
            self::PlateFormat => 'Plaque illisible',
            self::VinInvalid => 'VIN invalide (somme de contrôle)',
            self::DateUnreadable => 'Date de première immatriculation illisible',
            self::Blurry => 'Photo floue : reprenez-la de face',
            self::Cropped => 'Document coupé : cadrez-le en entier',
            self::Dark => 'Photo sombre : reprenez-la avec plus de lumière',
            self::Glare => 'Reflet sur le document : changez l’angle',
            self::PlateDoubtful => 'Plaque douteuse : vérifiez-la',
            self::VinDoubtful => 'VIN douteux : vérifiez les derniers caractères',
            self::DateDoubtful => 'Date douteuse : vérifiez-la',
            self::Incomplete => 'Lecture incomplète, vérifiez les champs',
        };
    }
}
