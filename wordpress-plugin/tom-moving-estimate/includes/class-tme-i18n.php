<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * French and English text for the customer form, its REST errors and the customer email.
 * English strings are the keys; French is looked up by the English text.
 */
final class TME_I18n
{
    public const LANGUAGES = array('en', 'fr');

    /** Stored home-size values stay English so admin, reports and AI exports keep working. */
    private const HOME_SIZE_LABELS = array(
        'Studio'       => 'Studio',
        '1 bedroom'    => '1 chambre (3 ½)',
        '2 bedrooms'   => '2 chambres (4 ½)',
        '3 bedrooms'   => '3 chambres (5 ½)',
        '4+ bedrooms'  => '4 chambres et plus',
        'House'        => 'Maison',
        'Storage unit' => 'Mini-entrepôt',
    );

    private const FR = array(
        // Form page.
        'Tom Moving' => 'Déménagement Tom',
        'Request your moving estimate' => 'Obtenir ma soumission',
        'Tell us about your move first. After your information is saved, you can add photos or record a guided video walkthrough.' => 'Parlez-nous d\'abord de votre déménagement. Une fois vos informations enregistrées, vous pourrez ajouter des photos ou filmer une visite vidéo guidée.',
        'Your moving information' => 'Vos informations',
        'Fields marked with * are required' => 'Les champs marqués d\'un * sont obligatoires',
        'Full name *' => 'Nom complet *',
        'Email' => 'Courriel',
        'Phone *' => 'Téléphone *',
        'Move date *' => 'Date du déménagement *',
        'Current address *' => 'Adresse actuelle *',
        'Destination address' => 'Adresse de destination',
        'Estimated home size' => 'Taille du logement',
        'Select home size' => 'Choisissez la taille',
        'Special items or notes' => 'Objets spéciaux ou notes',
        'Piano, antiques, storage areas, stairs, elevator details, items that are not moving...' => 'Piano, objets lourds ou fragiles, escaliers, ascenseur à réserver, stationnement, ce qui ne déménage pas...',
        'I agree that Tom Moving may use this information to prepare my estimate. If I add photos or video, the files will be stored privately and automatically deleted after %d days.' => 'J\'accepte que Déménagement Tom utilise ces informations pour préparer ma soumission. Si j\'ajoute des photos ou une vidéo, elles sont gardées en privé et supprimées automatiquement après %d jours.',
        'Continue' => 'Continuer',
        'Your submission is used only to prepare your moving estimate.' => 'Vos informations servent seulement à préparer votre soumission.',
        'Add visual details' => 'Photos ou vidéo',
        'Your required information is saved' => 'Vos informations sont enregistrées',
        'How would you like to show the move?' => 'Comment voulez-vous nous montrer le déménagement ?',
        'Thank you, %s. Choose photos or a guided video walkthrough.' => 'Merci, %s. Choisissez des photos ou une visite vidéo guidée.',
        'Upload photos' => 'Ajouter des photos',
        'Add up to %d photos of the rooms, furniture and access route.' => 'Jusqu\'à %d photos des pièces, des meubles et du chemin jusqu\'au camion.',
        'Start video walkthrough' => 'Filmer une visite vidéo',
        'Record the parking route, entrances, stairs, rooms, furniture and items.' => 'Filmez le stationnement, les entrées, les escaliers, les pièces, les meubles et les objets.',
        'Photo walkthrough' => 'Photos',
        'Step 2 of 2' => 'Étape 2 de 2',
        'Add photos of your move' => 'Ajoutez des photos de votre déménagement',
        'Hello %s. Include furniture, rooms, stairs, entrances and the truck parking route.' => 'Bonjour %s. Montrez les meubles, les pièces, les escaliers, les entrées et l\'endroit où le camion peut se garer.',
        'Choose photos' => 'Choisir des photos',
        'Take a photo' => 'Prendre une photo',
        '%1$d of %2$d photos' => '%1$d photos sur %2$d',
        'No photos selected yet.' => 'Aucune photo choisie pour l\'instant.',
        'Submit photos' => 'Envoyer les photos',
        'Video walkthrough' => 'Visite vidéo',
        'Show us the full moving route' => 'Montrez-nous tout le parcours',
        'Hello %s. Move slowly and describe what you see.' => 'Bonjour %s. Avancez lentement et décrivez ce que vous voyez.',
        'Start outside: show where the truck can park and the route to the entrance.' => 'Commencez dehors : montrez où le camion peut se garer et le chemin jusqu\'à l\'entrée.',
        'Show entrances, elevators, stairs, hallways and tight corners.' => 'Montrez les entrées, l\'ascenseur, les escaliers, les corridors et les coins serrés.',
        'Visit every room and say the room name.' => 'Passez dans chaque pièce en disant son nom à voix haute.',
        'Point out furniture that is moving and clearly identify anything staying behind.' => 'Montrez les meubles qui partent et dites clairement ce qui reste sur place.',
        'Show boxes, loose items, basement, garage, storage and every floor.' => 'Montrez les boîtes, les objets en vrac, le sous-sol, le garage, le rangement et chaque étage.',
        'Ready to record?' => 'Prêt à filmer ?',
        'Your browser will ask for camera and microphone access. Nothing is uploaded until you review it and press Submit.' => 'Votre navigateur va demander l\'accès à la caméra et au micro. Rien n\'est envoyé avant que vous ayez revu la vidéo et appuyé sur Envoyer.',
        'Enable camera' => 'Activer la caméra',
        'Or choose an existing video' => 'Ou choisissez une vidéo déjà filmée',
        'Switch camera' => 'Changer de caméra',
        'Exit full-screen recording' => 'Quitter le plein écran',
        'Exit full screen' => 'Quitter le plein écran',
        'Hold your phone horizontally when possible.' => 'Tenez votre téléphone à l\'horizontale si possible.',
        'Start recording' => 'Commencer',
        'Stop recording' => 'Arrêter',
        'Retake' => 'Recommencer',
        'Submit video' => 'Envoyer la vidéo',
        'Thank you' => 'Merci',
        'Thank you, %s!' => 'Merci, %s !',
        'Your moving-estimate request was received. Tom Moving will review it and follow up with you.' => 'Votre demande de soumission est bien reçue. Déménagement Tom va l\'examiner et vous revenir.',

        // Browser messages (public.js).
        'Something went wrong. Please try again.' => 'Une erreur est survenue. Veuillez réessayer.',
        '%s upload failed (%d). Please try again.' => 'L\'envoi a échoué (%2$d). Veuillez réessayer.',
        'The browser could not reach private media storage. Check your connection and try again.' => 'Le navigateur n\'arrive pas à joindre le stockage privé. Vérifiez votre connexion et réessayez.',
        'Your photos were uploaded securely. Tom Moving will review them and follow up with you.' => 'Vos photos sont bien envoyées. Déménagement Tom va les examiner et vous revenir.',
        'Your video walkthrough was uploaded securely. Tom Moving will review it and follow up with you.' => 'Votre visite vidéo est bien envoyée. Déménagement Tom va l\'examiner et vous revenir.',
        'Your moving information was received. Tom Moving will review it and follow up with you.' => 'Vos informations sont bien reçues. Déménagement Tom va les examiner et vous revenir.',
        'there' => '',
        'The media walkthrough could not be started. Please try again.' => 'Impossible de commencer l\'envoi de photos ou de vidéo. Veuillez réessayer.',
        'You can submit up to %d photos.' => 'Vous pouvez envoyer jusqu\'à %d photos.',
        'Please choose JPEG, PNG, WebP, HEIC or HEIF photos.' => 'Choisissez des photos JPEG, PNG, WebP, HEIC ou HEIF.',
        'Each photo must be smaller than %d MB.' => 'Chaque photo doit faire moins de %d Mo.',
        'Selected photo %d' => 'Photo choisie %d',
        'Photo %d' => 'Photo %d',
        'Remove' => 'Retirer',
        'Remove %s' => 'Retirer %s',
        'Preparing photo %1$d of %2$d...' => 'Préparation de la photo %1$d sur %2$d...',
        'Uploading photo %1$d of %2$d... %3$d%%' => 'Envoi de la photo %1$d sur %2$d... %3$d %%',
        'Verifying photos...' => 'Vérification des photos...',
        'The photos could not be submitted. Please try again.' => 'Les photos n\'ont pas pu être envoyées. Veuillez réessayer.',
        'Hold your phone horizontally when possible, then walk at a comfortable pace.' => 'Tenez votre téléphone à l\'horizontale si possible, et marchez à un rythme confortable.',
        'Review your walkthrough before sending it.' => 'Revoyez votre visite avant de l\'envoyer.',
        'This browser cannot record directly. Choose an existing video instead, or use the current Safari, Chrome or Edge browser.' => 'Ce navigateur ne peut pas filmer directement. Choisissez une vidéo déjà filmée, ou utilisez une version récente de Safari, Chrome ou Edge.',
        'Camera or microphone access was blocked. Allow access in your browser settings, then try again. You can also choose an existing video.' => 'L\'accès à la caméra ou au micro est bloqué. Autorisez-le dans les réglages du navigateur, puis réessayez. Vous pouvez aussi choisir une vidéo déjà filmée.',
        'Recording stopped unexpectedly. Please try again.' => 'L\'enregistrement s\'est arrêté. Veuillez réessayer.',
        'No video was captured. Please try again.' => 'Aucune vidéo n\'a été enregistrée. Veuillez réessayer.',
        'This recording is larger than %d MB. Please retake a shorter walkthrough.' => 'Cet enregistrement dépasse %d Mo. Veuillez filmer une visite plus courte.',
        'Recording %s - maximum 15:00' => 'Enregistrement %s - maximum 15:00',
        'This browser could not start the recorder. Try the current Safari, Chrome or Edge browser.' => 'Ce navigateur n\'a pas pu lancer l\'enregistrement. Essayez une version récente de Safari, Chrome ou Edge.',
        'This video is larger than %d MB. Please choose or record a shorter video.' => 'Cette vidéo dépasse %d Mo. Choisissez ou filmez une vidéo plus courte.',
        'Please choose an MP4, WebM or QuickTime video.' => 'Choisissez une vidéo MP4, WebM ou QuickTime.',
        'The video is longer than 15 minutes. Please choose or record a shorter walkthrough.' => 'La vidéo dure plus de 15 minutes. Choisissez ou filmez une visite plus courte.',
        'Preparing secure upload...' => 'Préparation de l\'envoi sécurisé...',
        'Uploading securely... %d%%' => 'Envoi sécurisé... %d %%',
        'Verifying upload...' => 'Vérification de l\'envoi...',
        'Upload failed. Please try again.' => 'L\'envoi a échoué. Veuillez réessayer.',
        'Submitting...' => 'Envoi...',
        'The estimate could not be started. Please try again.' => 'La soumission n\'a pas pu être commencée. Veuillez réessayer.',

        // REST errors (class-tme-public.php).
        'Too many requests. Please try again later.' => 'Trop de demandes. Veuillez réessayer plus tard.',
        'Please choose information, photos or video.' => 'Choisissez informations, photos ou vidéo.',
        'Private media storage is being configured. Please try again shortly.' => 'Le stockage privé est en cours de configuration. Veuillez réessayer bientôt.',
        'Please complete every required field.' => 'Veuillez remplir tous les champs obligatoires.',
        'Please enter a valid email address.' => 'Veuillez entrer une adresse courriel valide.',
        'Please choose a home size.' => 'Veuillez choisir la taille du logement.',
        'Please choose a valid future move date.' => 'Veuillez choisir une date de déménagement à venir.',
        'Please confirm how your estimate information and media may be used.' => 'Veuillez confirmer comment vos informations, photos et vidéos peuvent être utilisées.',
        'Estimate session not found.' => 'Soumission introuvable.',
        'This estimate has already started a photo or video walkthrough.' => 'Cette soumission a déjà commencé un envoi de photos ou de vidéo.',
        'Please choose photos or video.' => 'Choisissez photos ou vidéo.',
        'Too many upload attempts. Please try again later.' => 'Trop de tentatives d\'envoi. Veuillez réessayer plus tard.',
        'Video estimate session not found.' => 'Soumission vidéo introuvable.',
        'A video has already been submitted for this estimate.' => 'Une vidéo a déjà été envoyée pour cette soumission.',
        'This upload link has expired. Please start a new estimate.' => 'Ce lien d\'envoi a expiré. Veuillez commencer une nouvelle soumission.',
        'Please use an MP4, WebM or QuickTime video.' => 'Utilisez une vidéo MP4, WebM ou QuickTime.',
        'The video must be smaller than %d MB.' => 'La vidéo doit faire moins de %d Mo.',
        'Too many photo upload attempts. Please try again later.' => 'Trop de tentatives d\'envoi de photos. Veuillez réessayer plus tard.',
        'Photo estimate session not found.' => 'Soumission photo introuvable.',
        'Photos have already been submitted for this estimate.' => 'Des photos ont déjà été envoyées pour cette soumission.',
        'The photo upload could not be prepared.' => 'L\'envoi de la photo n\'a pas pu être préparé.',
        'This photo changed before upload. Remove it and add it again.' => 'Cette photo a changé avant l\'envoi. Retirez-la et ajoutez-la de nouveau.',
        'The upload could not be verified.' => 'L\'envoi n\'a pas pu être vérifié.',
        'The upload has not finished yet. Wait a moment and try again.' => 'L\'envoi n\'est pas terminé. Attendez un moment et réessayez.',
        'The uploaded video is too large.' => 'La vidéo envoyée est trop lourde.',
        'Choose between 1 and %d photos.' => 'Choisissez entre 1 et %d photos.',
        'One of the photo uploads could not be verified.' => 'Une des photos n\'a pas pu être vérifiée.',
        'A photo has not finished uploading yet. Wait a moment and try again.' => 'Une photo n\'a pas fini d\'être envoyée. Attendez un moment et réessayez.',
        'An uploaded photo is too large.' => 'Une des photos envoyées est trop lourde.',
        'An uploaded file is not a supported photo.' => 'Un des fichiers envoyés n\'est pas une photo acceptée.',
        'The estimate could not be created.' => 'La soumission n\'a pas pu être créée.',

        // Customer email.
        'moving information' => 'informations sur le déménagement',
        'video walkthrough' => 'visite vidéo',
        '%d photo' => '%d photo',
        '%d photos' => '%d photos',
        'We received your moving-estimate request' => 'Nous avons bien reçu votre demande de soumission',
        "Hi %1\$s,\n\nWe received your moving-estimate request (%2\$s). Tom Moving will review it and follow up with you." => "Bonjour %1\$s,\n\nNous avons bien reçu votre demande de soumission (%2\$s). Déménagement Tom va l'examiner et vous revenir.",
        'Your photos or video are stored privately and automatically deleted after %d days.' => 'Vos photos ou votre vidéo sont gardées en privé et supprimées automatiquement après %d jours.',
    );

    public static function lang($value): string
    {
        $value = strtolower(substr(trim((string) $value), 0, 2));
        return in_array($value, self::LANGUAGES, true) ? $value : 'en';
    }

    /** Language of the page the shortcode runs on, from the site or translation plugin locale. */
    public static function page_lang(): string
    {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        return str_starts_with(strtolower((string) $locale), 'fr') ? 'fr' : 'en';
    }

    public static function t(string $text, string $lang): string
    {
        if ($lang === 'fr' && isset(self::FR[$text])) {
            return self::FR[$text];
        }
        return $text;
    }

    public static function home_size_label(string $size, string $lang): string
    {
        return $lang === 'fr' ? (self::HOME_SIZE_LABELS[$size] ?? $size) : $size;
    }

    /** Every browser-side string, keyed by its English text, for public.js. */
    public static function client_strings(string $lang): array
    {
        if ($lang !== 'fr') {
            return array();
        }
        return self::FR;
    }
}
