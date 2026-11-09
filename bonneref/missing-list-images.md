# Part categories without a 3D mesh (bonneref)

Date: 2026-10-16. Read-only analysis, no partsapi or Anthropic call.

## Scope and method

- Trees: the 5 French (lang 6) `getSearchTree` entries cached in Postgres, all PC: Mazda 6 Hatchback GG 2.0 (carId 19107, 497 leaves), Peugeot 307 1.4 (16182, 610), and three other cached cars (carIds 11753, 18679, 58143; 542, 557, 549 leaves; make/model not looked up). The English tree of 19107 (lang 4) was ignored. Leaves are unioned by STR_ID.
- Excluded like `FinderCategoryList`: the « Outils spéciaux » branch, leaves named « Outils », « Recherche d'un article par graphique », « Graphique complet des pièces de carrosserie ». Per car that leaves 489 / 596 / 529 / 545 / 541 leaves.
- Mapping: the real `partFamily({path, name})` of `bonneref/src/family.ts`, bundled with esbuild and run on each leaf's `STR_PATH` (no dimensions, no product group). `kind: photo` means no mesh (catalogue photo if the cluster has one, else « ? »). `brake` and `filter` kinds count as mapped (dedicated renders).
- Product groups: handled separately in the last-but-one section (`PRODUCT_GROUP_RULES` can only move a part to photo or to the tie rod end mesh).
- Mesh inventory checked against `bonneref/public/parts/SOURCES.md` (all rule targets exist).

## Counts

| | leaves |
| --- | --- |
| Union of leaves (STR_ID) | 702 |
| With a mesh or dedicated render, on every car's label | 154 |
| With a mesh only under some cars' label (naming-dependent) | 2 |
| **No mesh (« ? » or catalogue photo)** | **546** |

Of the 156 mapped: 2 exact mesh rules (no caption), 145 approximate (captioned « Forme type de la famille »), 7 brake render, 2 oil filter render.
Of the 546 unmapped: 132 are pinned on purpose to the photo (`mesh: null` rule or « habitacle »: gearboxes, tools, timing chain parts, gaskets, repair kits, cabin filter...) and 414 simply match no rule; those pinned lines carry `[pinned]`.

38 STR_IDs carry two label variants across cars (e.g. « Embrayage/composants » vs « Embrayage / composants »); the most frequent label is shown. Mapping can depend on the label (2 cases, below).

Each branch is split « common » (tier 1-2: wear parts and everyday repairs a DIY owner or mechanic searches) and « rare », ordered by search frequency; the ordering is judgement, not data. Branches are ordered the same way, body and accessories last.

## Missing leaves by branch

### Dispositif de freinage (17: 10 common, 7 rare)

Common

- 100036 Dispositif de freinage > Conduites de frein
- 100028 Dispositif de freinage > Cylindre de roue
- 100035 Dispositif de freinage > Flexibles de frein
- 102965 Dispositif de freinage > Frein à tambour > Cylindre de roue
- 100031 Dispositif de freinage > Frein à tambour > Garniture/machoire de frein
- 100033 Dispositif de freinage > Frein à tambour > Tambour de frein
- 100034 Dispositif de freinage > Freins de stationnement
- 100635 Dispositif de freinage > Levier / câble / tirette
- 102208 Dispositif de freinage > Liquide de frein
- 100631 Dispositif de freinage > Témoin d'usure

Rare

- 102964 Dispositif de freinage > Frein à tambour > Freins de stationnement
- 100633 Dispositif de freinage > Frein à tambour > Kit de réparation [pinned]
- 103210 Dispositif de freinage > Frein à tambour > Régleur de timonerie / accessoires
- 102248 Dispositif de freinage > Interrupteur des feux de freins
- 100039 Dispositif de freinage > Pompe à vide
- 100038 Dispositif de freinage > Réglage du comportement dynamique
- 100037 Dispositif de freinage > Régulateur de freinage [pinned]

### Filtre (3: 3 common, 0 rare)

Common

- 100263 Filtre > Filtre à air pour habitacle [pinned]
- 706725 Filtre > Filtre hydraulique
- 102901 Filtre > Kit de filtres

### Direction (11: 6 common, 5 rare)

Common

- 100602 Direction > Barre de connexion / composants > Kit de réparation [pinned]
- 102203 Direction > Huiles
- 100203 Direction > Levier de direction
- 100201 Direction > Soufflet / joint
- 100592 Direction > Tuyau de direction / conduite
- 101857 Direction > Vase d'expansion de l'huile hydraulique

Rare

- 102073 Direction > Détecteur de l'angle de braquage
- 100591 Direction > Filtre de direction
- 102766 Direction > Indicateur de pression d'huile
- 100594 Direction > Pièces de transmission de direction
- 104391 Direction > Volant /- composants

### Suspension d'essieu/Guidage des roues/Roues (11: 8 common, 3 rare)

Common

- 100209 Suspension d'essieu/Guidage des roues/Roues > Jambes de force / barres
- 102250 Suspension d'essieu/Guidage des roues/Roues > Moyeu de roue / fixation > Roulement, boîtier du roulement des roues
- 100596 Suspension d'essieu/Guidage des roues/Roues > Pièce de stabilisateur / pièces de fixation > Pièces de fixation [pinned]
- 100590 Suspension d'essieu/Guidage des roues/Roues > Pièce de stabilisateur / pièces de fixation > Support du balancier
- 100583 Suspension d'essieu/Guidage des roues/Roues > Rotules > Rotule de genouillère
- 100585 Suspension d'essieu/Guidage des roues/Roues > Support d'essieu / arbre d'essieu / fixation > Corps de l'essieu
- 100586 Suspension d'essieu/Guidage des roues/Roues > Support d'essieu / arbre d'essieu / fixation > Fixation
- 100761 Suspension d'essieu/Guidage des roues/Roues > Support d'essieu / arbre d'essieu / fixation > Support d'essieu/cadre auxiliaire

Rare

- 100834 Suspension d'essieu/Guidage des roues/Roues > Contrôle de pression des pneumatiques
- 102733 Suspension d'essieu/Guidage des roues/Roues > Écartement des roues élargi
- 100589 Suspension d'essieu/Guidage des roues/Roues > Fusée d'essieu / kit de réparation > Kit de réparation [pinned]

### Suspension/Amortissement (6: 4 common, 2 rare)

Common

- 100340 Suspension/Amortissement > Correcteur d'assiette / suspension pneumatique
- 100370 Suspension/Amortissement > Jeu de suspensions complet
- 101884 Suspension/Amortissement > Suspension pneumatique
- 100810 Suspension/Amortissement > Suspension sur ressorts à lames

Rare

- 102201 Suspension/Amortissement > Huiles
- 101830 Suspension/Amortissement > Set de vis pour châssis

### Entraînement des roues (1: 0 common, 1 rare)

Rare

- 100066 Entraînement des roues > Pièces de fixation/assessoires [pinned]

### Entraînement des essieux (7: 0 common, 7 rare)

Rare

- 100707 Entraînement des essieux > Arbre de transmission > Joint/ Flecteur [pinned]
- 100839 Entraînement des essieux > Arbre de transmission > Paliers/suspension
- 706919 Entraînement des essieux > Boîte de transfert > Fixation
- 706914 Entraînement des essieux > Boîte de transfert > Jeu de pièces pour vidange
- 706908 Entraînement des essieux > Boîte de transfert > Joints/Bagues d’étanchéité [pinned]
- 706814 Entraînement des essieux > Essieu complet
- 101996 Entraînement des essieux > Huiles

### Commande à courroie (3: 3 common, 0 rare)

Common

- 102029 Commande à courroie > Courroie crantée/kit de courroie crantée > Kit de galets
- 101971 Commande à courroie > Poulie
- 102239 Commande à courroie > Poulie roue libre, alternateur

### Allumage / préchauffage (5: 1 common, 4 rare)

Common

- 100152 Allumage / préchauffage > Bougie de préchauffage

Rare

- 100247 Allumage / préchauffage > Calculateur / relais
- 100148 Allumage / préchauffage > Distributeur d'allumage/Composants
- 100249 Allumage / préchauffage > Générateur d'impulsions
- 100246 Allumage / préchauffage > Module d'allumage/Appareil de commande

### Refroidissement (14: 10 common, 4 rare)

Common

- 102205 Refroidissement > Antigel
- 100096 Refroidissement > Joint/Thermostat > Joint [pinned]
- 100874 Refroidissement > Module de refroidissement
- 100089 Refroidissement > Pompe à eau / joint > Joint [pinned]
- 104956 Refroidissement > Pompe à eau / joint > Module de gestion thermique
- 102211 Refroidissement > Pompe à eau / joint > Poulie
- 100104 Refroidissement > Radiateur d'eau/d'huile > Échangeur de chaleur-habitacle [pinned]
- 100112 Refroidissement > Radiateur d'eau/d'huile > Vase d'expansion
- 100840 Refroidissement > Tuyauterie et brides > Brides
- 100215 Refroidissement > Tuyauterie et brides > Tuyaux d'huile / Conduites

Rare

- 102447 Refroidissement > Contrôle
- 100105 Refroidissement > Radiateur d'eau/d'huile > Pièces de fixation du radiateur [pinned]
- 100338 Refroidissement > Refroidissement par air
- 100344 Refroidissement > Relais

### Embrayage / composants (13: 10 common, 3 rare)

Common

- 100237 Embrayage / composants > Butée d’embrayage/butée hydraulique > Débrayage central
- 100056 Embrayage / composants > Butée d’embrayage/butée hydraulique > Douille de guidage
- 102080 Embrayage / composants > Butée d’embrayage/butée hydraulique > Fourchette de débrayage
- 100077 Embrayage / composants > Commande d'embrayage > Câble d'embrayage
- 100061 Embrayage / composants > Commande d'embrayage > Cylindre émetteur
- 100712 Embrayage / composants > Commande d'embrayage > Pédale
- 100060 Embrayage / composants > Commande d'embrayage > Récepteur d'embrayage
- 100298 Embrayage / composants > Commande d'embrayage > Tuyaux / conduites
- 104813 Embrayage / composants > Fluide hydraulique
- 100057 Embrayage / composants > Volant-moteur

Rare

- 100780 Embrayage / composants > Commande d'embrayage > Leviers/pièces de transmission
- 102784 Embrayage / composants > Joints/Kits de joints [pinned]
- 104718 Embrayage / composants > Kit adaptateur pour embrayage

### Moteur (74: 37 common, 37 rare)

Common

- 102235 Moteur > Alimentation en air > Flexibles/Conduites
- 100393 Moteur > Alimentation en air > Suralimentation > Refroidisseur d'air de suralimentation
- 104326 Moteur > Commande à courroie > Courroie crantée/kit de courroie crantée > Kit de galets
- 104329 Moteur > Commande à courroie > Poulie
- 104330 Moteur > Commande à courroie > Poulie roue libre, alternateur
- 100374 Moteur > Culasse / pieces de montage > Joint de culasse [pinned]
- 100378 Moteur > Culasse / pieces de montage > Vis de culasse [pinned]
- 100401 Moteur > Distribution moteur > Carter de distribution / joint
- 100411 Moteur > Distribution moteur > Chaîne de distribution/tension/guidage > Chaîne de distribution [pinned]
- 100413 Moteur > Distribution moteur > Chaîne de distribution/tension/guidage > Coulisse
- 103278 Moteur > Distribution moteur > Chaîne de distribution/tension/guidage > Kit de distribution par chaîne
- 100414 Moteur > Distribution moteur > Chaîne de distribution/tension/guidage > Tendeur de chaînes [pinned]
- 102027 Moteur > Distribution moteur > Courroie crantée/tension/guidage > Kit de galets
- 104695 Moteur > Distribution moteur > Poulie
- 100224 Moteur > Joints > Jeu de joints complet
- 100683 Moteur > Joints > Joint d'eau de refroidissement [pinned]
- 100235 Moteur > Joints > Joint de carter de distribution [pinned]
- 100229 Moteur > Joints > Joint de collecteur d'admission [pinned]
- 100231 Moteur > Joints > Joint de collecteur d'échappement [pinned]
- 100225 Moteur > Joints > Joint de culasse [pinned]
- 100228 Moteur > Joints > Joint de tige de soupape [pinned]
- 100233 Moteur > Joints > Joint du carter de moteur [pinned]
- 100789 Moteur > Joints > Kit-/bagues d'étanchéité
- 100491 Moteur > Lubrification > Carter d'huile et pièces de montage > Vis de vidange [pinned]
- 101994 Moteur > Lubrification > Huiles
- 100479 Moteur > Lubrification > Jauge d'huile
- 100488 Moteur > Lubrification > Radiateur d'huile / pièces de montage > Joint [pinned]
- 100481 Moteur > Lubrification > Tuyaux d'huile / Conduites
- 100536 Moteur > Support du moteur > Fixation moteur
- 100525 Moteur > Système bielle-manivelle > Bielle > Coussinet de bielle [pinned]
- 100527 Moteur > Système bielle-manivelle > Bielle > Coussinets de bielle [pinned]
- 100522 Moteur > Système bielle-manivelle > Vilebrequin > Coussinet de vilebrequin [pinned]
- 100759 Moteur > Système bielle-manivelle > Vilebrequin > Poulies de vilebrequin
- 100518 Moteur > Système bielle-manivelle > Volant-moteur
- 102993 Moteur > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Module-EGR
- 100549 Moteur > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Soupape, recyclage des gaz d'échappement
- 100551 Moteur > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Vanne EGR/Tube d'aspiration

Rare

- 100385 Moteur > Alimentation en air > Câble d'accélérateur/tringlerie
- 100706 Moteur > Alimentation en air > Papillon des gaz /-capteur > Capteur des papillons de gaz
- 100818 Moteur > Alimentation en air > Réglage/Commande
- 100392 Moteur > Alimentation en air > Suralimentation > Joint de turbocompresseur [pinned]
- 100394 Moteur > Alimentation en air > Suralimentation > Réglage d'air de suralimentation
- 102076 Moteur > Alimentation en air > Suralimentation > Tube d'air de suralimentation
- 100379 Moteur > Culasse / pieces de montage > Couvercle de remplissage d'huile/joint
- 100380 Moteur > Culasse / pieces de montage > Culasse
- 100376 Moteur > Culasse / pieces de montage > Joint de collecteur d'admission/des gaz d'échappement [pinned]
- 102778 Moteur > Culasse / pieces de montage > Joints toriques [pinned]
- 102450 Moteur > Culasse / pieces de montage > Pompe à vide
- 100399 Moteur > Distribution moteur > Poussoir /tige/ tuyau de protection
- 100545 Moteur > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Contrôle des gaz d'échappement de RGE
- 103235 Moteur > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Joints [pinned]
- 100543 Moteur > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Transmetteur de pression
- 100682 Moteur > Joints > Joint de carburant [pinned]
- 100684 Moteur > Joints > Joint de circuit d'huile [pinned]
- 100244 Moteur > Joints > Joint de turbocompresseur [pinned]
- 100234 Moteur > Joints > Joint du réservoir d'huile [pinned]
- 100472 Moteur > Lubrification > Boîtier de filtres à huile / joint
- 100490 Moteur > Lubrification > Carter d'huile et pièces de montage > Joint [pinned]
- 100478 Moteur > Lubrification > Indicateur de pression d'huile / capteur / soupape
- 100885 Moteur > Lubrification > Pompe à huile et pièces de montage > Chaîne de commande [pinned]
- 100493 Moteur > Lubrification > Pompe à huile et pièces de montage > Joint [pinned]
- 100521 Moteur > Système bielle-manivelle > Kit-/bague d'étanchéité d'arbre radial
- 103270 Moteur > Système bielle-manivelle > Piston > Kit de réparation [pinned]
- 103037 Moteur > Cache moteur
- 105860 Moteur > Carter de vilebrequin > Arbre intermédiaire/arbre à contrepoids d'équilibrage
- 100533 Moteur > Carter de vilebrequin > Carter de vilebrequin
- 100534 Moteur > Carter de vilebrequin > Kit-/chemises de cylindre
- 100754 Moteur > Circuit électrique du moteur
- 100425 Moteur > Distribution moteur > Pignon droit
- 101833 Moteur > Distribution moteur > roues dentées
- 100701 Moteur > Moteur complet / Partie du moteur > Moteur complet
- 103397 Moteur > Protection moteur/anti-encastrement
- 103120 Moteur > Système bielle-manivelle > Arbre intermédiaire/arbre à contrepoids d'équilibrage
- 103437 Moteur > Tuning

### Alimentation carburant (8: 5 common, 3 rare)

Common

- 100269 Alimentation carburant > Amortisseur de pulsation
- 100268 Alimentation carburant > Capteur de niveau de carburant
- 100718 Alimentation carburant > Pompe / accessoires > Accessoires/Composants
- 100719 Alimentation carburant > Pompe / accessoires > Réparation
- 100258 Alimentation carburant > Tuyauterie

Rare

- 100270 Alimentation carburant > Pressostat/manostat
- 100252 Alimentation carburant > Soupapes
- 706089 Alimentation carburant > Système AKF

### Carburation (21: 6 common, 15 rare)

Common

- 102806 Carburation > Mélange air-carburant > Débitmètre d'air
- 102801 Carburation > Mélange air-carburant > Pédale d'accélérateur/potenciomètre
- 102803 Carburation > Mélange air-carburant > Pompe à injection/pompe à haute pression
- 102992 Carburation > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Module-EGR
- 100816 Carburation > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Soupape, recyclage des gaz d'échappement
- 100811 Carburation > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Vanne EGR/Tube d'aspiration

Rare

- 102910 Carburation > Carburateurs > Commande / Amortisseurs / Boîtiers [pinned]
- 102906 Carburation > Carburateurs > Composants du carburateur [pinned]
- 100815 Carburation > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Contrôle des gaz d'échappement de RGE
- 103239 Carburation > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Joints [pinned]
- 100812 Carburation > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Transmetteur de pression
- 102802 Carburation > Mélange air-carburant > Câble/ tige d'accélérateur
- 102800 Carburation > Mélange air-carburant > Composants de porte-injecteur / Injecteur [pinned]
- 102804 Carburation > Mélange air-carburant > Conduite de carburant / répartition du carburant
- 102810 Carburation > Mélange air-carburant > Contrôle
- 102808 Carburation > Mélange air-carburant > Interrupteur / relais
- 102795 Carburation > Mélange air-carburant > Joint [pinned]
- 102807 Carburation > Mélange air-carburant > Kit de réparation/Kit complet [pinned]
- 102805 Carburation > Mélange air-carburant > Réglage du ralenti/réglage de mise en température
- 102811 Carburation > Mélange air-carburant > Soupapes/Bloc de soupapes
- 104704 Carburation > Mélange air-carburant > Transmetteur de pression

### Échappement (14: 3 common, 11 rare)

Common

- 100047 Échappement > Catalyseur
- 100318 Échappement > Filtre à particules
- 100316 Échappement > Pièces de montage > Assortiment de montage

Rare

- 103062 Échappement > Injection d'urée > Capteur
- 100325 Échappement > Pièces de montage > Pièces détachées de montage > Amortisseur-caoutchouc
- 100324 Échappement > Pièces de montage > Pièces détachées de montage > Bague en caoutchouc
- 100331 Échappement > Pièces de montage > Pièces détachées de montage > Bride de fixation
- 100323 Échappement > Pièces de montage > Pièces détachées de montage > Collier
- 100716 Échappement > Pièces de montage > Pièces détachées de montage > Gaine de soudure
- 100321 Échappement > Pièces de montage > Pièces détachées de montage > Joint [pinned]
- 100329 Échappement > Pièces de montage > Pièces détachées de montage > Pièce de serrage
- 100332 Échappement > Pièces de montage > Pièces détachées de montage > Ressort
- 100327 Échappement > Pièces de montage > Pièces détachées de montage > Support
- 100333 Échappement > Pièces de montage > Pièces détachées de montage > Vis/écrous/anneaux [pinned]

### Système électrique (65: 34 common, 31 rare)

Common

- 100352 Système électrique > Alternateur / composants > Composants [pinned]
- 100351 Système électrique > Alternateur / composants > Régulateur [pinned]
- 105798 Système électrique > Assortiments d'ampoules
- 100320 Système électrique > Avertisseur sonore / trompette
- 102336 Système électrique > Boîte à fusibles / support
- 100511 Système électrique > Centrale clignotante/Relais
- 104741 Système électrique > Commutateur multifonctionnel / unité de commande
- 100362 Système électrique > Démarreur > Contacteur [pinned]
- 101495 Système électrique > Feux > Feu arrière / composants > Ampoule pour feux arrières
- 100456 Système électrique > Feux > Feu arrière / composants > Composants de feu arrière [pinned]
- 100454 Système électrique > Feux > Feu arrière / composants > Feu arrière
- 100498 Système électrique > Feux > Feu clignotant/composants > Ampoule de feu clignotant
- 100480 Système électrique > Feux > Feu clignotant/composants > Composants de feu clignotant [pinned]
- 100463 Système électrique > Feux > Feu clignotant/composants > Feu clignotant
- 100500 Système électrique > Feux > Feu de brouillard arrière/Composants > Ampoules de feu de brouillard arrière
- 100503 Système électrique > Feux > Feu de position / d'encombrement / composants > Ampoule
- 100501 Système électrique > Feux > Feu de recul / composants > Ampoule de feu de recul
- 100870 Système électrique > Feux > Feu diurne
- 100499 Système électrique > Feux > Feu éclaireur de plaque/composants > Ampoule du feu éclaireur de plaque
- 100496 Système électrique > Feux > Feu-stop / composants > Ampoules pour feux de stop
- 100471 Système électrique > Feux > Feu-stop / composants > Composants des feux de stop [pinned]
- 100867 Système électrique > Feux > Feu-stop / composants > Feu stop additionnel
- 103066 Système électrique > Kit/pièces de câble
- 102671 Système électrique > Phares supplémentaires/composants > Éclairage de virage/composants
- 100444 Système électrique > Phares supplémentaires/composants > Projecteur antibrouillard/ composants > Composants pour projecteur antibrouillard [pinned]
- 100448 Système électrique > Phares supplémentaires/composants > Projecteur de travail, composants > Ampoule, projecteurs de travail
- 706659 Système électrique > Plombs
- 706742 Système électrique > Projecteurs principaux / composants > Ampoule /projecteur longue portée
- 100427 Système électrique > Projecteurs principaux / composants > Ampoule pour projecteur principal
- 100430 Système électrique > Projecteurs principaux / composants > Composants des projecteurs principaux [pinned]
- 100279 Système électrique > Projecteurs principaux / composants > Correcteur de portée lumineuse
- 706161 Système électrique > Projecteurs principaux / composants > Module/composants du phare principal
- 100723 Système électrique > Projecteurs principaux / composants > Phares au xénon
- 100433 Système électrique > Projecteurs principaux / composants > Projecteurs principaux / à insérer

Rare

- 100727 Système électrique > Appareils de commande
- 102747 Système électrique > Capteurs
- 100295 Système électrique > Cataphote arrière/Réflecteur latéral
- 100708 Système électrique > Électricité centrale
- 100291 Système électrique > Feux > Éclairage de porte
- 100505 Système électrique > Feux > Éclairage intérieur > Éclairage de l'habitacle [pinned]
- 101794 Système électrique > Feux > Éclairage intérieur > Éclairage de la boîte à gants
- 100508 Système électrique > Feux > Éclairage intérieur > Éclairage des instruments et des commandes
- 100507 Système électrique > Feux > Éclairage intérieur > Éclairage du coffre / du compartiment de chargement
- 100506 Système électrique > Feux > Éclairage intérieur > Éclairage du compartiment moteur
- 101803 Système électrique > Feux > Éclairage intérieur > Lampe de lecture
- 100484 Système électrique > Feux > Feu de brouillard arrière/Composants > Composants de feu de brouillard arrière [pinned]
- 100465 Système électrique > Feux > Feu de brouillard arrière/Composants > Feu antibrouillard arrière
- 100469 Système électrique > Feux > Feu de position / d'encombrement / composants > Feu d'encombrement
- 100468 Système électrique > Feux > Feu de position / d'encombrement / composants > Feu de stationnement/de position
- 100753 Système électrique > Feux > Feu de position / d'encombrement / composants > Feux latéraux
- 100482 Système électrique > Feux > Feu éclaireur de plaque/composants > Composants du feu éclaireur de plaque [pinned]
- 100464 Système électrique > Feux > Feu éclaireur de plaque/composants > Feu éclaireur de plaque
- 100553 Système électrique > Instruments > Capteurs/Conatct
- 100561 Système électrique > Instruments > Flexible de compteur de vitesse
- 100555 Système électrique > Instruments > Instruments indicateurs
- 100724 Système électrique > Interrupteur/Relais/Contrôle clignotant/Appareil d.commande > Appareils de commande
- 100509 Système électrique > Interrupteur/Relais/Contrôle clignotant/Appareil d.commande > Interrupteur / régulateur
- 103428 Système électrique > Minuterie multifonctions
- 100447 Système électrique > Projecteur auxiliaire/composants > Projecteur antibrouillard/ composants > Ampoule pour projecteur antibrouillard
- 100440 Système électrique > Projecteur auxiliaire/composants > Projecteur antibrouillard/ composants > Projecteur antibrouillard / Optique
- 100442 Système électrique > Projecteur auxiliaire/composants > Projecteur longue portée / composants > Ampoule /projecteur longue portée
- 100443 Système électrique > Projecteur auxiliaire/composants > Projecteur longue portée / composants > Projecteur longue portée / composants
- 100439 Système électrique > Projecteur auxiliaire/composants > Projecteur longue portée / composants > Projecteur longue portée/ optique
- 100869 Système électrique > Projecteur auxiliaire/composants > Projecteurs de virage / composants
- 100785 Système électrique > Relais

### Chauffage / Ventilation (8: 1 common, 7 rare)

Common

- 100346 Chauffage / Ventilation > Filtre d'habitacle [pinned]

Rare

- 100725 Chauffage / Ventilation > Appareils de commande
- 100348 Chauffage / Ventilation > Caisse de volets de chauffage
- 100347 Chauffage / Ventilation > Échangeur de chaleur-habitacle [pinned]
- 100726 Chauffage / Ventilation > Éléments de commande
- 100777 Chauffage / Ventilation > Gaines/tuyaux
- 101470 Chauffage / Ventilation > Préchauffage de l'eau de refroidissmt
- 100349 Chauffage / Ventilation > Pulseur d'air habitacle composants [pinned]

### Climatisation (12: 5 common, 7 rare)

Common

- 100354 Climatisation > Compresseur / composants
- 100355 Climatisation > Condenseur
- 100357 Climatisation > Dessicateur
- 100356 Climatisation > Évaporateur
- 100360 Climatisation > Tuyaux/Conduites

Rare

- 100728 Climatisation > Calculateur
- 100794 Climatisation > Capteurs
- 100779 Climatisation > Contrôle/Réglage
- 100366 Climatisation > Interrupteur
- 102922 Climatisation > Kit de réparation [pinned]
- 100865 Climatisation > Résistance additionnelle
- 100358 Climatisation > Soupapes

### Boîte de vitesses (30: 7 common, 23 rare)

Common

- 101814 Boîte de vitesses > Boîte de vitesse automatique > Convertisseur de couple [pinned]
- 103475 Boîte de vitesses > Boîte de vitesse automatique > Jeu de pièces pour vidange [pinned]
- 103065 Boîte de vitesses > Boîte de vitesses manuelle > Boîte de vitesses [pinned]
- 101808 Boîte de vitesses > Boîte de vitesses manuelle > Commande de boîte de vitesses [pinned]
- 706086 Boîte de vitesses > Boîte de vitesses manuelle > Support / coussinet / palier [pinned]
- 102685 Boîte de vitesses > Boîte de vitesses manuelle > Synchronisateurs [pinned]
- 706233 Boîte de vitesses > Huiles [pinned]

Rare

- 100775 Boîte de vitesses > Boîte de vitesse automatique > Boîtier/composants [pinned]
- 102931 Boîte de vitesses > Boîte de vitesse automatique > Capteur [pinned]
- 101854 Boîte de vitesses > Boîte de vitesse automatique > Carter d'huile et pièces de montage > Carter d'huile [pinned]
- 101855 Boîte de vitesses > Boîte de vitesse automatique > Carter d'huile et pièces de montage > Joint [pinned]
- 100776 Boîte de vitesses > Boîte de vitesse automatique > Contrôle / hydraulique [pinned]
- 100773 Boîte de vitesses > Boîte de vitesse automatique > Fixation [pinned]
- 100770 Boîte de vitesses > Boîte de vitesse automatique > Joints [pinned]
- 105494 Boîte de vitesses > Boîte de vitesse automatique > Pompe à huile [pinned]
- 103109 Boîte de vitesses > Boîte de vitesse automatique > Radiateur [pinned]
- 102215 Boîte de vitesses > Boîte de vitesse automatique > Ruban de frein [pinned]
- 103436 Boîte de vitesses > Boîte de vitesse automatique > Tirette à câble [pinned]
- 102930 Boîte de vitesses > Boîte de vitesses manuelle > Capteur [pinned]
- 100774 Boîte de vitesses > Boîte de vitesses manuelle > Carter/composants [pinned]
- 103103 Boîte de vitesses > Boîte de vitesses manuelle > Contrôle / hydraulique [pinned]
- 100772 Boîte de vitesses > Boîte de vitesses manuelle > Fixation [pinned]
- 100769 Boîte de vitesses > Boîte de vitesses manuelle > Joints [pinned]
- 103110 Boîte de vitesses > Boîte de vitesses manuelle > Radiateur [pinned]
- 101809 Boîte de vitesses > Boîte de vitesses manuelle > Réparation/entretien [pinned]
- 706636 Boîte de vitesses > Boîte de vitesses manuelle robotisée (BVR) > Capteurs/Conatct [pinned]
- 706625 Boîte de vitesses > Boîte de vitesses manuelle robotisée (BVR) > Carter d'huile et pièces de montage > Carter d'huile [pinned]
- 706586 Boîte de vitesses > Boîte de vitesses manuelle robotisée (BVR) > Carter/composants [pinned]
- 706621 Boîte de vitesses > Boîte de vitesses manuelle robotisée (BVR) > Contrôle / hydraulique [pinned]
- 706612 Boîte de vitesses > Boîte de vitesses manuelle robotisée (BVR) > Cylindre de commande [pinned]

### Roues/Pneus (4: 2 common, 2 rare)

Common

- 103101 Roues/Pneus > Jantes
- 103100 Roues/Pneus > Pneus

Rare

- 103102 Roues/Pneus > Accessoires
- 103479 Roues/Pneus > Contrôle de pression des pneumatiques

### Nettoyage des vitres (9: 6 common, 3 rare)

Common

- 100133 Nettoyage des vitres > Balai d'essuie-glace / raclette [pinned]
- 100136 Nettoyage des vitres > Bras d'essuie-glace / fixation [pinned]
- 100611 Nettoyage des vitres > Gicleur d'eau de lavage [pinned]
- 100134 Nettoyage des vitres > Moteur d'essuie-glace [pinned]
- 100135 Nettoyage des vitres > Pompe-à-eau / essuie-glaces [pinned]
- 100610 Nettoyage des vitres > Réservoir d'eau de lavage / conduite [pinned]

Rare

- 100613 Nettoyage des vitres > Interrupteur / relais [pinned]
- 100612 Nettoyage des vitres > Système de nettoyage des vitres [pinned]
- 100137 Nettoyage des vitres > Tringlerie d'essuie-glace / commande [pinned]

### Nettoyage des phares (1: 0 common, 1 rare)

Rare

- 100342 Nettoyage des phares

### Entraînement hybride/électrique (1: 0 common, 1 rare)

Rare

- 706816 Entraînement hybride/électrique > Refroidissement > Capteurs

### Système pneumatique (2: 0 common, 2 rare)

Rare

- 103171 Système pneumatique > Dessicateur d'air / cartouche
- 706494 Système pneumatique > Interrupteur

### Pièces de maintenance (2: 0 common, 2 rare)

Rare

- 100597 Pièces de maintenance > Intervalle
- 100598 Pièces de maintenance > Travaux supplémentaires

### Dispositif d'attelage/accessoires (3: 0 common, 3 rare)

Rare

- 100609 Dispositif d'attelage/accessoires > Composants / pièces de montage [pinned]
- 100607 Dispositif d'attelage/accessoires > Dispositif d'attelage/accessoires
- 100608 Dispositif d'attelage/accessoires > Pièces du système électrique

### Systèmes de sécurité (5: 0 common, 5 rare)

Rare

- 100680 Systèmes de sécurité > Immobilisateur antivol
- 706455 Systèmes de sécurité > Système d'aide à la conduite
- 100762 Systèmes de sécurité > Système d'air-bag
- 100419 Systèmes de sécurité > Système d'alarme
- 100421 Systèmes de sécurité > Système de ceintures

### Système d'information et de communication (5: 0 common, 5 rare)

Rare

- 100695 Système d'information et de communication > Antennes
- 100696 Système d'information et de communication > Communication
- 100693 Système d'information et de communication > Instruments
- 100697 Système d'information et de communication > Navigation
- 100694 Système d'information et de communication > Système audio

### Systèmes de confort (16: 0 common, 16 rare)

Rare

- 100618 Systèmes de confort > Aide au stationnement/Alarme de marche arrière
- 100850 Systèmes de confort > Capteur de pluie
- 100614 Systèmes de confort > Chauffage de stationnement
- 104155 Systèmes de confort > Commande du hayon
- 706789 Systèmes de confort > Contrôle
- 100751 Systèmes de confort > Lève-vitre
- 105584 Systèmes de confort > Moteur / Relais > Capot moteur
- 104169 Systèmes de confort > Moteur / Relais > Déverrouillage de trappe à carburant
- 104157 Systèmes de confort > Moteur / Relais > Lève-vitre
- 104159 Systèmes de confort > Moteur / Relais > Mirroirs
- 104163 Systèmes de confort > Moteur / Relais > Régulateur de vitesse [pinned]
- 104158 Systèmes de confort > Moteur / Relais > Toit ouvrant / capot levable
- 104164 Systèmes de confort > Moteur / Relais > Verrouillage central
- 102736 Systèmes de confort > Préchauffage du moteur (électrique)
- 100615 Systèmes de confort > Régulateur de vitesse [pinned]
- 100617 Systèmes de confort > Verrouillage central

### Verrouillage (9: 0 common, 9 rare)

Rare

- 706506 Verrouillage > Clés
- 100819 Verrouillage > Commande à distance, verrouillage central
- 100766 Verrouillage > Composants [pinned]
- 100690 Verrouillage > Cylindre de serrure / jeu
- 100689 Verrouillage > Kit de verrous
- 100691 Verrouillage > Poignées
- 100687 Verrouillage > Serrure extérieure
- 100688 Verrouillage > Serrure intérieure
- 100686 Verrouillage > Verrouillage central

### Équipement intérieur (13: 0 common, 13 rare)

Rare

- 100730 Équipement intérieur > Accessoires
- 102689 Équipement intérieur > Accoudoir
- 104958 Équipement intérieur > Clips de fixation
- 102697 Équipement intérieur > Coffre à bagages / compartiment de chargement
- 102691 Équipement intérieur > Lève-vitre
- 102695 Équipement intérieur > Leviers manuels / Leviers de pédale
- 102698 Équipement intérieur > Pare-soleil
- 102694 Équipement intérieur > Poignées
- 102706 Équipement intérieur > Recouvrement
- 100673 Équipement intérieur > Sièges
- 102998 Équipement intérieur > Tableau de bord
- 103400 Équipement intérieur > Tapis bac auto
- 102692 Équipement intérieur > Tapis de plancher

### Carrosserie (139: 23 common, 116 rare)

Common

- 101721 Carrosserie > Arrière de la voiture > Feu arrière/Composants > Ampoule de feu arrière
- 101730 Carrosserie > Arrière de la voiture > Feu clignotant/composants > Ampoule de feu clignotant
- 101738 Carrosserie > Arrière de la voiture > Feu de brouillard arrière/Composants > Ampoules de feu de brouillard arrière
- 101789 Carrosserie > Arrière de la voiture > Feu de position / d'encombrement / composants > Ampoule
- 101742 Carrosserie > Arrière de la voiture > Feu de recul / composants > Ampoule de feu de recul
- 101734 Carrosserie > Arrière de la voiture > Feu éclaireur de plaque/composants > Ampoule du feu éclaireur de plaque
- 101726 Carrosserie > Arrière de la voiture > Feu-stop / composants > Ampoules pour feux de stop
- 101672 Carrosserie > Avant du véhicule > Feu clignotant/composants > Ampoule de feu clignotant
- 101770 Carrosserie > Avant du véhicule > Feu de position / d'encombrement / composants > Ampoule
- 101663 Carrosserie > Avant du véhicule > Projecteur antibrouillard/ composants > Ampoule pour projecteur antibrouillard
- 101667 Carrosserie > Avant du véhicule > Projecteur longue portée / composants > Ampoule /projecteur longue portée
- 101658 Carrosserie > Avant du véhicule > Projecteurs principaux / composants > Ampoule pour projecteur principal
- 101414 Carrosserie > Feux > Feu arrière/Composants > Ampoule de feu arrière
- 101423 Carrosserie > Feux > Feu clignotant/composants > Ampoule de feu clignotant
- 101431 Carrosserie > Feux > Feu de brouillard arrière/Composants > Ampoules de feu de brouillard arrière
- 101445 Carrosserie > Feux > Feu de position / d'encombrement / composants > Ampoule
- 101435 Carrosserie > Feux > Feu de recul / composants > Ampoule de feu de recul
- 101427 Carrosserie > Feux > Feu éclaireur de plaque/composants > Ampoule du feu éclaireur de plaque
- 101419 Carrosserie > Feux > Feu-stop / composants > Ampoules pour feux de stop
- 101351 Carrosserie > Phares supplémentaires/composants > Projecteur de travail, composants > Ampoule, projecteurs de travail
- 101343 Carrosserie > Projecteur auxiliaire/composants > Projecteur antibrouillard/ composants > Ampoule pour projecteur antibrouillard
- 101347 Carrosserie > Projecteur auxiliaire/composants > Projecteur longue portée / composants > Ampoule /projecteur longue portée
- 101467 Carrosserie > Projecteurs principaux / composants > Ampoule pour projecteur principal

Rare

- 101779 Carrosserie > Arrière de la voiture > Aile avant/composants
- 101783 Carrosserie > Arrière de la voiture > Capot avant / composants / insonorisation
- 101751 Carrosserie > Arrière de la voiture > Composants/suppléments > Support pour éclairage
- 101717 Carrosserie > Arrière de la voiture > Feu arrière / composants > Composants de feu arrière [pinned]
- 101716 Carrosserie > Arrière de la voiture > Feu arrière / composants > Feu arrière
- 101719 Carrosserie > Arrière de la voiture > Feu arrière/Composants > Feu arrière
- 101729 Carrosserie > Arrière de la voiture > Feu clignotant/composants > Composants de feu clignotant [pinned]
- 101728 Carrosserie > Arrière de la voiture > Feu clignotant/composants > Feu clignotant
- 101737 Carrosserie > Arrière de la voiture > Feu de brouillard arrière/Composants > Composants de feu de brouillard arrière [pinned]
- 101736 Carrosserie > Arrière de la voiture > Feu de brouillard arrière/Composants > Feu antibrouillard arrière
- 101787 Carrosserie > Arrière de la voiture > Feu de position / d'encombrement / composants > Feu d'encombrement
- 101786 Carrosserie > Arrière de la voiture > Feu de position / d'encombrement / composants > Feu de stationnement/de position
- 101788 Carrosserie > Arrière de la voiture > Feu de position / d'encombrement / composants > Feux latéraux
- 101732 Carrosserie > Arrière de la voiture > Feu éclaireur de plaque/composants > Feu éclaireur de plaque
- 101723 Carrosserie > Arrière de la voiture > Feu-stop / composants > Feu stop additionnel
- 101706 Carrosserie > Arrière de la voiture > Pare-chocs/composants
- 101650 Carrosserie > Avant du véhicule > Aile avant/composants
- 101654 Carrosserie > Avant du véhicule > Capot avant / composants / insonorisation
- 101671 Carrosserie > Avant du véhicule > Feu clignotant/composants > Composants de feu clignotant [pinned]
- 101670 Carrosserie > Avant du véhicule > Feu clignotant/composants > Feu clignotant
- 101768 Carrosserie > Avant du véhicule > Feu de position / d'encombrement / composants > Feu d'encombrement
- 101767 Carrosserie > Avant du véhicule > Feu de position / d'encombrement / composants > Feu de stationnement/de position
- 101769 Carrosserie > Avant du véhicule > Feu de position / d'encombrement / composants > Feux latéraux
- 101655 Carrosserie > Avant du véhicule > Pare-brise
- 101649 Carrosserie > Avant du véhicule > Pare-chocs/composants
- 101664 Carrosserie > Avant du véhicule > Projecteur antibrouillard/ composants > Composants pour projecteur antibrouillard [pinned]
- 101662 Carrosserie > Avant du véhicule > Projecteur antibrouillard/ composants > Projecteur antibrouillard / Optique
- 101668 Carrosserie > Avant du véhicule > Projecteur longue portée / composants > Projecteur longue portée / composants
- 101666 Carrosserie > Avant du véhicule > Projecteur longue portée / composants > Projecteur longue portée/ optique
- 101659 Carrosserie > Avant du véhicule > Projecteurs principaux / composants > Composants des projecteurs principaux [pinned]
- 101657 Carrosserie > Avant du véhicule > Projecteurs principaux / composants > Projecteurs principaux / à insérer
- 101452 Carrosserie > Feux > Éclairage de porte
- 101410 Carrosserie > Feux > Feu arrière / composants > Composants de feu arrière [pinned]
- 101409 Carrosserie > Feux > Feu arrière / composants > Feu arrière
- 101412 Carrosserie > Feux > Feu arrière/Composants > Feu arrière
- 101422 Carrosserie > Feux > Feu clignotant/composants > Composants de feu clignotant [pinned]
- 101421 Carrosserie > Feux > Feu clignotant/composants > Feu clignotant
- 102406 Carrosserie > Feux > Feu clignotant/composants > Feu clignotant latéral
- 101430 Carrosserie > Feux > Feu de brouillard arrière/Composants > Composants de feu de brouillard arrière [pinned]
- 101429 Carrosserie > Feux > Feu de brouillard arrière/Composants > Feu antibrouillard arrière
- 101443 Carrosserie > Feux > Feu de position / d'encombrement / composants > Feu d'encombrement
- 101442 Carrosserie > Feux > Feu de position / d'encombrement / composants > Feu de stationnement/de position
- 101444 Carrosserie > Feux > Feu de position / d'encombrement / composants > Feux latéraux
- 101425 Carrosserie > Feux > Feu éclaireur de plaque/composants > Feu éclaireur de plaque
- 101416 Carrosserie > Feux > Feu-stop / composants > Feu stop additionnel
- 101699 Carrosserie > Habitacle > Feu stop additionnel [pinned]
- 101698 Carrosserie > Habitacle > Miroirs [pinned]
- 101694 Carrosserie > Habitacle > Portière avant/composants [pinned]
- 101697 Carrosserie > Habitacle > Vitre de portière/vitre latérale [pinned]
- 101344 Carrosserie > Phares supplémentaires/composants > Projecteur antibrouillard/ composants > Composants pour projecteur antibrouillard [pinned]
- 100185 Carrosserie > Pièces de carrosserie/ailes/pares-chocs > Aile avant/composants
- 100187 Carrosserie > Pièces de carrosserie/ailes/pares-chocs > Pare-chocs/composants
- 101342 Carrosserie > Projecteur auxiliaire/composants > Projecteur antibrouillard/ composants > Projecteur antibrouillard / Optique
- 101348 Carrosserie > Projecteur auxiliaire/composants > Projecteur longue portée / composants > Projecteur longue portée / composants
- 101346 Carrosserie > Projecteur auxiliaire/composants > Projecteur longue portée / composants > Projecteur longue portée/ optique
- 101468 Carrosserie > Projecteurs principaux / composants > Composants des projecteurs principaux [pinned]
- 101466 Carrosserie > Projecteurs principaux / composants > Projecteurs principaux / à insérer
- 100184 Carrosserie > Trappes/capots/portes/toit coul./toit panoram./toit pliant > Capot avant / composants / insonorisation
- 100192 Carrosserie > Trappes/capots/portes/toit coul./toit panoram./toit pliant > Clapet/couvercle de hayon/de coffre
- 100189 Carrosserie > Trappes/capots/portes/toit coul./toit panoram./toit pliant > Portière avant/composants
- 102731 Carrosserie > Trappes/capots/portes/toit coul./toit panoram./toit pliant > Trappe à carburant/composants
- 100566 Carrosserie > Vitrages/Miroirs > Miroirs
- 100568 Carrosserie > Vitrages/Miroirs > Vitrage > Joints [pinned]
- 100569 Carrosserie > Vitrages/Miroirs > Vitrage > Pièces de montage
- 102346 Carrosserie > Vitrages/Miroirs > Vitrage > Vitrage collé
- 100567 Carrosserie > Vitrages/Miroirs > Vitrage > Vitres
- 103036 Carrosserie > Arrière de la voiture > Cache moteur
- 101782 Carrosserie > Arrière de la voiture > Clapet/couvercle de hayon/de coffre
- 101750 Carrosserie > Arrière de la voiture > Composants/suppléments > Enjoliveur/bande protectrice
- 101746 Carrosserie > Arrière de la voiture > Composants/suppléments > Recouvrement/Capuchons
- 101752 Carrosserie > Arrière de la voiture > Composants/suppléments > Ressorts pneumatiques
- 101747 Carrosserie > Arrière de la voiture > Composants/suppléments > Spoiler
- 101749 Carrosserie > Arrière de la voiture > Composants/suppléments > Support et plaque d'immatriculation
- 101781 Carrosserie > Arrière de la voiture > Hayon/composants
- 101784 Carrosserie > Arrière de la voiture > Lunette arrière
- 101780 Carrosserie > Arrière de la voiture > Panneau latéral
- 101710 Carrosserie > Arrière de la voiture > Passage de roue
- 101708 Carrosserie > Arrière de la voiture > Recouvrement
- 103035 Carrosserie > Avant du véhicule > Cache moteur
- 103064 Carrosserie > Avant du véhicule > Composants/suppléments > Assortiment de montage
- 101774 Carrosserie > Avant du véhicule > Composants/suppléments > Emblèmes
- 101679 Carrosserie > Avant du véhicule > Composants/suppléments > Enjoliveur/bande protectrice
- 101775 Carrosserie > Avant du véhicule > Composants/suppléments > Fixation de radiateur
- 101773 Carrosserie > Avant du véhicule > Composants/suppléments > Fixation/Support/collerette
- 101674 Carrosserie > Avant du véhicule > Composants/suppléments > Recouvrement/Capuchons
- 101675 Carrosserie > Avant du véhicule > Composants/suppléments > Recouvrement/radiateur
- 101777 Carrosserie > Avant du véhicule > Composants/suppléments > Ressorts pneumatiques
- 101778 Carrosserie > Avant du véhicule > Composants/suppléments > Spoiler
- 101776 Carrosserie > Avant du véhicule > Composants/suppléments > Support et plaque d'immatriculation
- 101652 Carrosserie > Avant du véhicule > Passage de roue
- 100217 Carrosserie > Fixation de radiateur
- 101454 Carrosserie > Fixation/Support/collerette
- 100741 Carrosserie > Grilles décoratives, logos, anti-éclaboussures > Emblèmes
- 100740 Carrosserie > Grilles décoratives, logos, anti-éclaboussures > Enjoliveur/bande protectrice
- 101691 Carrosserie > Habitacle > Cadre de montant de toit / Cadre de montant de porte [pinned]
- 101702 Carrosserie > Habitacle > Composants/suppléments > Enjoliveur/bande protectrice [pinned]
- 101703 Carrosserie > Habitacle > Composants/suppléments > Ressorts pneumatiques [pinned]
- 101701 Carrosserie > Habitacle > Composants/suppléments > Spoiler [pinned]
- 101692 Carrosserie > Habitacle > Marchepied / montant de porte [pinned]
- 101696 Carrosserie > Habitacle > Panneau latéral [pinned]
- 101693 Carrosserie > Habitacle > Plancher de carrosserie [pinned]
- 101760 Carrosserie > Habitacle > Réservoir de carburant/pièces détachées [pinned]
- 100155 Carrosserie > Pièces de carrosserie/ailes/pares-chocs > Barre latérale / transversale
- 100737 Carrosserie > Pièces de carrosserie/ailes/pares-chocs > Cadre de montant de toit / Cadre de montant de porte
- 104959 Carrosserie > Pièces de carrosserie/ailes/pares-chocs > Clips de fixation
- 100169 Carrosserie > Pièces de carrosserie/ailes/pares-chocs > Marchepied / montant de porte
- 100181 Carrosserie > Pièces de carrosserie/ailes/pares-chocs > Panneau latéral
- 100165 Carrosserie > Pièces de carrosserie/ailes/pares-chocs > Passage de roue
- 100168 Carrosserie > Pièces de carrosserie/ailes/pares-chocs > Plancher de carrosserie
- 101504 Carrosserie > Pièces de carrosserie/ailes/pares-chocs > Plot de berceau/Support d'agrégats
- 100699 Carrosserie > Pièces de carrosserie/ailes/pares-chocs > Recouvrement/Capuchons
- 100191 Carrosserie > Pièces de carrosserie/ailes/pares-chocs > Revêtement / grille avant
- 100739 Carrosserie > Pièces de carrosserie/ailes/pares-chocs > Revêtement arrière
- 100537 Carrosserie > Pièces de carrosserie/ailes/pares-chocs > Spoiler
- 100606 Carrosserie > Pièces de carrosserie/ailes/pares-chocs > Support et plaque d'immatriculation
- 100826 Carrosserie > Ressorts pneumatiques

### Accessoires (12: 0 common, 12 rare)

Rare

- 100860 Accessoires > Accoudoir
- 100854 Accessoires > Allume-cigares
- 706700 Accessoires > Bavette/garde-boue
- 103485 Accessoires > Chaîne à neige
- 100862 Accessoires > Coffre/chargement/plateau de chargement
- 706769 Accessoires > Cric
- 103443 Accessoires > Déflecteur
- 706519 Accessoires > Divers
- 104631 Accessoires > Kit de revêtement de pédales
- 104736 Accessoires > Protection anti-mustélidés
- 100857 Accessoires > Tapis
- 103396 Accessoires > Tapis bac auto

### Portes-bagages (2: 0 common, 2 rare)

Rare

- 100367 Portes-bagages > Barres de toit
- 100368 Portes-bagages > Porte-bagages à l'arrière

## Naming-dependent

Same STR_ID, mesh or not depending on the car's label of the node. Where the label does not match, the leaf shows « ? ».

- 100227 Moteur > Joints > Joint de couvre-culasse → no mesh (18679, 11753, Peugeot 307 16182)
- 100227 Moteur > Joints > Cache-soupape → valve-cover-gasket (Mazda 6 19107, 58143)
- 100040 Système électrique > Démarreur > Composants → no mesh (18679, 11753, Peugeot 307 16182)
- 100040 Système électrique > Démarreur > Composants du démarreur → starter-gear (Mazda 6 19107, 58143)

Exact mesh, no caption (can only be improved by a vehicle-specific mesh): 100151 Allumage / préchauffage > Bougie d'allumage → spark-plug; 100198 Direction > Rotules → outer-tie-rod-end.

## Mapped approximately

These get a mesh, captioned « Forme type de la famille, pas la pièce exacte » (one car's game part, not the user's). An exact mesh would also help here.

### Dispositif de freinage (6)

- 100025 Dispositif de freinage > Servofrein → brake-master-booster
- 100026 Dispositif de freinage > Maître-cylindre de frein → brake-master-booster
- 100030 Dispositif de freinage > Frein à disque > Plaquette de frein → front-brake-pad
- 100630 Dispositif de freinage > Frein à disque > Accessoires / composants → brake-hardware-1
- 100634 Dispositif de freinage > Frein à tambour > Accessoires / composants → brake-hardware-1
- 102227 Dispositif de freinage > Frein de haute performance > Plaquettes de freins de haute performance → front-brake-pad

### Filtre (2)

- 100260 Filtre > Filtre à air → air-filter
- 100261 Filtre > Filtre à carburant → fuel-filter

### Direction (5)

- 100197 Direction > Barre de connexion / composants > Barre de connexion → outer-tie-rod-end
- 100199 Direction > Crémaillère de direction/pompe → steering-rack
- 100202 Direction > Colonne de direction / arbre → steering-column
- 100593 Direction > Suspension de la direction → steering-rack-bracket
- 100603 Direction > Barre de connexion / composants > Composants de barre de connexion → outer-tie-rod-end

### Suspension d'essieu/Guidage des roues/Roues (11)

- 100213 Suspension d'essieu/Guidage des roues/Roues > Roue / fixation de la roue → wheel-stud
- 100571 Suspension d'essieu/Guidage des roues/Roues > Bras oscillant / suspension > Bras (transversal, longitudinal, oblique) → front-lower-control-arm
- 100572 Suspension d'essieu/Guidage des roues/Roues > Bras oscillant / suspension > Fixation/placement → lower-control-arm-bushing
- 100573 Suspension d'essieu/Guidage des roues/Roues > Pièce de stabilisateur / pièces de fixation > Stabilisateur → front-sway-bar
- 100577 Suspension d'essieu/Guidage des roues/Roues > Pièce de stabilisateur / pièces de fixation > Barre de suspension → sway-bar-end-link
- 100578 Suspension d'essieu/Guidage des roues/Roues > Moyeu de roue / fixation > Moyeu de roue → front-hub
- 100579 Suspension d'essieu/Guidage des roues/Roues > Moyeu de roue / fixation > Roulement de roue → rear-wheel-bearing
- 100581 Suspension d'essieu/Guidage des roues/Roues > Rotules > Rotule de suspension → outer-tie-rod-end
- 100587 Suspension d'essieu/Guidage des roues/Roues > Fusée d'essieu / kit de réparation > Fusée d'essieu → front-upright
- 100735 Suspension d'essieu/Guidage des roues/Roues > Fixation jambe de force / suspension → strut-top-mount
- 100803 Suspension d'essieu/Guidage des roues/Roues > Moyeu de roue / fixation > Bout d'essieu → axle-nut

### Suspension/Amortissement (5)

- 100113 Suspension/Amortissement > Suspension → coil-spring
- 100121 Suspension/Amortissement > Amortisseurs → front-shock-absorber
- 100129 Suspension/Amortissement > Jambe de suspension/Amortisseur/Pièces d'assemblage > Jambe de suspension → front-shock-absorber
- 100371 Suspension/Amortissement > Coupelle de suspension/d'amortisseur → strut-top-mount
- 100372 Suspension/Amortissement > Jambe de suspension/Amortisseur/Pièces de montage > Pièces de montage → front-shock-bushing

### Entraînement des roues (4)

- 100062 Entraînement des roues > Arbre de transmission → axles
- 100070 Entraînement des roues > Tripode → axles
- 100071 Entraînement des roues > Rotules/ jeu → axles
- 100074 Entraînement des roues > Soufflet → axles

### Entraînement des essieux (2)

- 100415 Entraînement des essieux > Différentiel → differential
- 100805 Entraînement des essieux > Arbre de transmission > Arbre de transmission → drive-shaft

### Commande à courroie (17)

- 100431 Commande à courroie > Jeu/ courroies trapézoïdales à nervures > Courroie trapézoïdale à nervures → timing-belt
- 100432 Commande à courroie > Jeu/ courroies trapézoïdales à nervures > Jeu de courroie trapézoïdale à nervures → timing-belt
- 100434 Commande à courroie > Jeu/ courroies trapézoïdales à nervures > Poulie de tension → tensioner-pulley
- 100435 Commande à courroie > Jeu/ courroies trapézoïdales à nervures > Poulie de renvoi/poulie de guidage → idler-pulley
- 100438 Commande à courroie > Jeu/ courroies trapézoïdales à nervures > Bras tendeur → tensioner-pulley
- 100441 Commande à courroie > Jeu/ courroies trapézoïdales à nervures > Tendeurs de courroies → tensioner-pulley
- 100450 Commande à courroie > Jeu/ courroies trapèzoïdales > Courroie trapèzoïdale → timing-belt
- 100452 Commande à courroie > Courroie crantée/kit de courroie crantée > Courroie crantée → timing-belt
- 100453 Commande à courroie > Courroie crantée/kit de courroie crantée > Kit de courroie crantée → timing-belt
- 100455 Commande à courroie > Courroie crantée/kit de courroie crantée > Poulie de tension → tensioner-pulley
- 100457 Commande à courroie > Courroie crantée/kit de courroie crantée > Poulie de renvoi/poulie de guidage → idler-pulley
- 100461 Commande à courroie > Courroie crantée/kit de courroie crantée > Tendeurs de courroies → tensioner-pulley
- 100765 Commande à courroie > Jeu/ courroies trapèzoïdales > Poulie de renvoi → idler-pulley
- 100876 Commande à courroie > Jeu/ courroies trapézoïdales à nervures > Amortisseur de vibrations → crankshaft-pulley-damper
- 100877 Commande à courroie > Courroie crantée/kit de courroie crantée > Amortisseur de vibrations → crankshaft-pulley-damper
- 101482 Commande à courroie > Accessoires → alternator-tensioner
- 102016 Commande à courroie > Courroie crantée/kit de courroie crantée > Cache de la courroie crantée → timing-belt

### Allumage / préchauffage (2)

- 100150 Allumage / préchauffage > Bobine/unite de bobine d'allumage → ignition-coil-pack
- 100153 Allumage / préchauffage > Raccords de câble d'allumage → ignition-coil-boot

### Refroidissement (7)

- 100091 Refroidissement > Pompe à eau / joint > Pompe à eau → water-pump
- 100095 Refroidissement > Joint/Thermostat > Thermostat → thermostat
- 100100 Refroidissement > Tuyauterie et brides > Durite de refroidissement/conduite → upper-radiator-hose
- 100103 Refroidissement > Radiateur d'eau/d'huile > Radiateur d'eau/composants → radiator
- 100108 Refroidissement > Radiateur d'eau/d'huile > Radiateur d'huile → oil-cooler
- 100334 Refroidissement > Interrupteur / capteur → coolant-temperature-sensor
- 100337 Refroidissement > Ventilateur → cooling-fan

### Embrayage / composants (5)

- 100051 Embrayage / composants > Kit d'embrayage → clutch-disc
- 100052 Embrayage / composants > Mécanisme d'embrayage → clutch-pressure-plate
- 100053 Embrayage / composants > Disque d'embrayage → clutch-disc
- 100055 Embrayage / composants > Butée d’embrayage/butée hydraulique > Butée de débrayage → throwout-bearing
- 100059 Embrayage / composants > Palier guide / Rondelle de butée → pilot-bearing

### Moteur (52)

- 100227 Moteur > Joints > Cache-soupape → valve-cover-gasket
- 100375 Moteur > Culasse / pieces de montage > Cache-soupape → valve-cover
- 100377 Moteur > Culasse / pieces de montage > Guide de soupape / joint / réglage → valve-spring
- 100384 Moteur > Alimentation en air > Filtre à air / carter → air-filter
- 100386 Moteur > Alimentation en air > Collecteur d'admission/Tuyau → intake-manifold-upper
- 100391 Moteur > Alimentation en air > Suralimentation > Compresseur/composants → efr-turbo
- 100397 Moteur > Distribution moteur > Arbre à cames → intake-cam
- 100398 Moteur > Distribution moteur > Culbuteur/Levier oscillant → hla
- 100404 Moteur > Distribution moteur > Courroie crantée/tension/guidage > Courroie crantée → timing-belt
- 100405 Moteur > Distribution moteur > Courroie crantée/tension/guidage > Kit de courroie crantée → timing-belt
- 100406 Moteur > Distribution moteur > Courroie crantée/tension/guidage > Poulie de tension → tensioner-pulley
- 100407 Moteur > Distribution moteur > Courroie crantée/tension/guidage > Poulie de renvoi/poulie de guidage → idler-pulley
- 100410 Moteur > Distribution moteur > Courroie crantée/tension/guidage > Tendeurs de courroies → tensioner-pulley
- 100423 Moteur > Distribution moteur > Soupape / réglage de soupape > Soupapes / accessoires → intake-valve
- 100424 Moteur > Distribution moteur > Soupape / réglage de soupape > Réglage de soupape → hla
- 100483 Moteur > Lubrification > Radiateur d'huile / pièces de montage > Radiateur d'huile → oil-cooler
- 100489 Moteur > Lubrification > Carter d'huile et pièces de montage > Carter d'huile → oil-pan
- 100492 Moteur > Lubrification > Pompe à huile et pièces de montage > Pompe à huile → oil-pump
- 100513 Moteur > Lubrification > Thermostat d'huile → thermostat
- 100524 Moteur > Système bielle-manivelle > Bielle > Bielle → connecting-rod
- 100529 Moteur > Système bielle-manivelle > Piston > Piston → piston
- 100530 Moteur > Système bielle-manivelle > Piston > Piston complet → piston
- 100531 Moteur > Système bielle-manivelle > Piston > Jeu de segments de piston → compression-ring
- 100538 Moteur > Support du moteur > Support moteur → engine-mount
- 100539 Moteur > Support du moteur > Amortisseurs de support moteur → engine-mount
- 100729 Moteur > Épuration des gaz d'échappement > Régulation lambda → oxygen-sensor
- 100875 Moteur > Distribution moteur > Courroie crantée/tension/guidage > Amortisseur de vibrations → crankshaft-pulley-damper
- 101485 Moteur > Distribution moteur > Chaîne de distribution/tension/guidage > Culbuteur/levier oscillant/pièce de press° → hla
- 102015 Moteur > Distribution moteur > Courroie crantée/tension/guidage > Cache de la courroie crantée → timing-belt
- 102339 Moteur > Alimentation en air > Papillon des gaz /-capteur > Corps papillon → itb-tb
- 102683 Moteur > Système bielle-manivelle > Vilebrequin > Vilebrequin → crankshaft
- 103237 Moteur > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Radiateur → radiator
- 103421 Moteur > Alimentation en air > Suralimentation > Radiateur basse température → radiator
- 104304 Moteur > Commande à courroie > Jeu/ courroies trapèzoïdales > Courroie trapèzoïdale → timing-belt
- 104306 Moteur > Commande à courroie > Jeu/ courroies trapèzoïdales > Poulie de renvoi → idler-pulley
- 104309 Moteur > Commande à courroie > Jeu/ courroies trapézoïdales à nervures > Courroie trapézoïdale à nervures → timing-belt
- 104310 Moteur > Commande à courroie > Jeu/ courroies trapézoïdales à nervures > Jeu de courroie trapézoïdale à nervures → timing-belt
- 104311 Moteur > Commande à courroie > Jeu/ courroies trapézoïdales à nervures > Poulie de tension → tensioner-pulley
- 104312 Moteur > Commande à courroie > Jeu/ courroies trapézoïdales à nervures > Poulie de renvoi/poulie de guidage → idler-pulley
- 104313 Moteur > Commande à courroie > Jeu/ courroies trapézoïdales à nervures > Bras tendeur → tensioner-pulley
- 104314 Moteur > Commande à courroie > Jeu/ courroies trapézoïdales à nervures > Tendeurs de courroies → tensioner-pulley
- 104315 Moteur > Commande à courroie > Jeu/ courroies trapézoïdales à nervures > Amortisseur de vibrations → crankshaft-pulley-damper
- 104318 Moteur > Commande à courroie > Courroie crantée/kit de courroie crantée > Courroie crantée → timing-belt
- 104319 Moteur > Commande à courroie > Courroie crantée/kit de courroie crantée > Kit de courroie crantée → timing-belt
- 104320 Moteur > Commande à courroie > Courroie crantée/kit de courroie crantée > Poulie de tension → tensioner-pulley
- 104321 Moteur > Commande à courroie > Courroie crantée/kit de courroie crantée > Poulie de renvoi/poulie de guidage → idler-pulley
- 104323 Moteur > Commande à courroie > Courroie crantée/kit de courroie crantée > Tendeurs de courroies → tensioner-pulley
- 104324 Moteur > Commande à courroie > Courroie crantée/kit de courroie crantée > Amortisseur de vibrations → crankshaft-pulley-damper
- 104325 Moteur > Commande à courroie > Courroie crantée/kit de courroie crantée > Cache de la courroie crantée → timing-belt
- 104328 Moteur > Commande à courroie > Accessoires → alternator-tensioner
- 104377 Moteur > Cylindre et piston → piston
- 105862 Moteur > Carter de vilebrequin > Ventilation → pcv-valve

### Alimentation carburant (4)

- 100250 Alimentation carburant > Réservoir de carburant/accessoires → fuel-tank
- 100253 Alimentation carburant > Boîtier/filtre à carburant → fuel-filter
- 100267 Alimentation carburant > Unité d'alimentation complète → fuel-sender
- 100717 Alimentation carburant > Pompe / accessoires > Pompe d'alimentation → fuel-sender

### Carburation (6)

- 100793 Carburation > Épuration des gaz d'échappement > Régulation lambda → oxygen-sensor
- 102796 Carburation > Mélange air-carburant > Papillon des gaz / tubulure / tuyau de dépression → itb-tb
- 102799 Carburation > Mélange air-carburant > Injecteur/porte-injecteur/UPI → injector
- 102809 Carburation > Mélange air-carburant > Capteur/sonde lambda → oxygen-sensor
- 103047 Carburation > Épuration des gaz d'échappement > Capteur/sonde lambda → oxygen-sensor
- 103241 Carburation > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Radiateur → radiator

### Échappement (7)

- 100046 Échappement > Silencieux d'échappement (système) → muffler
- 100048 Échappement > Capteur lambda → oxygen-sensor
- 100314 Échappement > Silencieux → muffler
- 100315 Échappement > Tuyaux → oem-mid-pipe
- 100715 Échappement > Collecteur → stock-manifold
- 100872 Échappement > Turbocompresseur → efr-turbo
- 103414 Échappement > Capteur/sonde lambda → oxygen-sensor

### Système électrique (4)

- 100040 Système électrique > Démarreur > Composants du démarreur → starter-gear
- 100042 Système électrique > Batterie → battery
- 100350 Système électrique > Alternateur / composants > Alternateur → alternator
- 100359 Système électrique > Démarreur > Démarreur → starter

### Entraînement hybride/électrique (3)

- 706215 Entraînement hybride/électrique > Refroidissement > Radiateur pour moteur électrique → radiator
- 706706 Entraînement hybride/électrique > Refroidissement > Pompe à eau pour entraînement électrique → water-pump
- 706747 Entraînement hybride/électrique > Refroidissement > Tuyauterie et brides → cooling-hardpipe

### Carrosserie (3)

- 100216 Carrosserie > Réservoir de carburant/pièces détachées → fuel-tank
- 101651 Carrosserie > Avant du véhicule > Réservoir de carburant/pièces détachées → fuel-tank
- 101763 Carrosserie > Arrière de la voiture > Réservoir de carburant/pièces détachées → fuel-tank

## Product groups seen inside mixed leaves

Source: 41 of the 63 cached `getArticles` entries could be tied to (carId, strId) of the five cars (the 22 others belong to cars or categories not in these trees). `partFamily(category, [], productGroup)` was run per group. Only leaves with several product groups are listed; PT = TecDoc product type id, count = articles in the cached listing.

- 100260 Filtre > Filtre à air (Mazda 6, 145 articles) leaf → air-filter~
  - PT 8 « Filtre à air » ×144 → air-filter~
  - PT 3884 « Système de filtres à air sport » ×1 → air-filter~
- 706725 Filtre > Filtre hydraulique (Mazda 6, 106 articles) leaf → photo
  - PT 416 « Filtre hydraulique, boîte automatique » ×46 → photo
  - PT 417 « Filtre hydraulique, direction » ×11 → photo
  - PT 3843 « Kit de filtre hydraulique, boîte automatique » ×49 → photo
- 100384 Moteur > Alimentation en air > Filtre à air / carter (Mazda 6, 149 articles) leaf → air-filter~
  - PT 8 « Filtre à air » ×144 → air-filter~
  - PT 3884 « Système de filtres à air sport » ×1 → air-filter~
  - PT 1591 « Tuyau d'aspiration, alimentation d'air » ×2 → air-filter~
  - PT 8147 « Boîte, filtre à air » ×2 → air-filter~
- 104329 Moteur > Commande à courroie > Poulie (Mazda 6, 36 articles) leaf → photo
  - PT 3213 « Poulie, vilebrequin » ×28 → photo
  - PT 542 « Jeu de courroies trapézoïdales à nervures » ×6 → photo
  - PT 4204 « Poulie, pompe de direction assistée » ×2 → photo
- 100538 Moteur > Support du moteur > Support moteur (Mazda 6, 105 articles) leaf → engine-mount~
  - PT 247 « Support moteur » ×104 → engine-mount~
  - PT 1545 « Butée élastique, support du moteur » ×1 → engine-mount~
- 100334 Refroidissement > Interrupteur / capteur (Mazda 6, 32 articles) leaf → coolant-temperature-sensor~
  - PT 7284 « Kit rép. de câble, capteur de temp. liquide de refroidis. » ×1 → coolant-temperature-sensor~
  - PT 830 « Sonde, température du liquide de refroidissement » ×29 → coolant-temperature-sensor~
  - PT 829 « Capteur, température d'huile » ×2 → coolant-temperature-sensor~
- 103066 Système électrique > Kit/pièces de câble (Mazda 6, 2 articles) leaf → photo
  - PT 3796 « Câblage, préchauffage du moteur » ×1 → photo
  - PT 3797 « Câblage, chauffage d'habitacle (préchauffage du moteur) » ×1 → photo
- 100198 Direction > Rotules (307, 804 articles) leaf → outer-tie-rod-end
  - PT 51 « Rotule de direction intérieure, barre de connexion » ×288 → photo
  - PT 914 « Rotule de barre de connexion » ×514 → outer-tie-rod-end
  - PT 2066 « Rotule de direction » ×2 → photo
- 100259 Filtre > Filtre à huile (307, 402 articles) leaf → filter
  - PT 7 « Filtre à huile » ×386 → filter
  - PT 133 « Joint, filtre à huile » ×12 → filter
  - PT 60777 « Module de filtre à huile » ×4 → filter
- 706383 Outils spéciaux > Outils spéciaux suspension d’essieu/guidage des roues/roues > Rotules (307, 2 articles) leaf → photo
  - PT 4559 « Clé à douille, Suspension articulée / écrou cannelé » ×1 → photo
  - PT 8525 « Clé à tenon, suspension articulée » ×1 → photo
- 100538 Moteur > Support du moteur > Support moteur (18679, 327 articles) leaf → engine-mount~
  - PT 247 « Support moteur » ×325 → engine-mount~
  - PT 483 « Support de palier, support du moteur » ×2 → engine-mount~
- 100095 Refroidissement > Joint/Thermostat > Thermostat (58143, 125 articles) leaf → thermostat~
  - PT 316 « Thermostat, liquide de refroidissement » ×102 → thermostat~
  - PT 1792 « Boîtier du thermostat » ×23 → thermostat~

Reading (`~` = approximate mesh):

- Updated 2026-10-16: `PRODUCT_GROUP_RULES` now splits « Direction > Rotules » (PT 914 exact tie rod end, PT 51 and PT 2066 photo) and sends to photo the groups below that are not the leaf's part.
- Fixed 2026-10-16 (now photo, no mesh): « Filtre à huile » PT 133 « Joint, filtre à huile » and PT 60777 « Module de filtre à huile »; « Filtre à air / carter » PT 1591 « Tuyau d'aspiration » and PT 8147 « Boîte, filtre à air »; « Interrupteur / capteur » PT 829 « Capteur, température d'huile » and PT 7284 (cable kit); « Thermostat » PT 1792 « Boîtier du thermostat »; « Support moteur » PT 483 « Support de palier » and PT 1545 « Butée élastique ». The other groups of those leaves keep the leaf mesh. No other mixed leaf of this section has the problem (« Filtre à air » PT 3884 sport system is still an air filter).
- Photo-only mixes (no mesh for any group): « Filtre hydraulique » (PT 416, 417, 3843: automatic gearbox and steering hydraulic filters), « Moteur > Commande à courroie > Poulie » (PT 3213 « Poulie, vilebrequin », PT 542 belt set, PT 4204 power steering pump pulley), « Kit/pièces de câble » (PT 3796, 3797).
- Done 2026-10-16: PT 3213 « Poulie, vilebrequin » (28 of the 36 articles of 104329) now draws the existing `crankshaft-pulley-damper` mesh (approximate) via a product-group rule; PT 542 and PT 4204 stay photo.
- « Outils spéciaux > … > Rotules » (706383, 307) holds socket wrenches (PT 4559, 8525): excluded from the agent list, would show « ? ».
- Single-group cached leaves behave like their category rule (e.g. Mazda « Filtre à huile » 220 articles → filter render).

## Notes

- Fixed 2026-10-16: `102763 Frein à tambour > Kit de freins` and `102225 Frein de haute performance > Kit de freins` matched the `BRAKE` regex (`kit de freins`) and got the disc brake assembly. Both now show no mesh (photo): a drum kit is not a disc, and a performance kit may be drums or discs, so it is left undrawn. « Frein à disque > Kit de freins » keeps the brake render.
- Known extraction gaps (`bonneref/ROADMAP.md` § 3D assets, « Not available as an extracted mesh »): EGR, A/C compressor and condenser, coolant expansion tank, vacuum pump, timing chain, clutch slave cylinder, catalytic converter, gearbox body. They show up above as: EGR (Carburation 100811/100815/100816/102992/103239/100812 and Moteur 100545-100551/102993/103235/100543), A/C (Climatisation 100354, 100355), expansion tank (100112, 101857 for the power steering reservoir), vacuum pump (100039, 102450), timing chain (Moteur Distribution moteur 100411-100414, 103278), clutch slave cylinder (100060, 100061), catalytic converter (100047), gearbox body (103065, 100774, 101808...).
- Coverage numbers are for the 5 cached trees only; any other car adds leaves under the same STR_IDs plus a few new ones.
