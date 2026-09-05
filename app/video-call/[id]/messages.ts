/**
 * Client-facing copy for the live call, in English and Canadian French. The
 * rep chooses the language when starting the call (from WordPress); the
 * client's entire experience — including this page's error states and the
 * contact form — renders in that language. The rep's own page (RepCall.tsx)
 * is intentionally not localized; only the customer-facing side is.
 */

export type Locale = "en" | "fr";

export function isLocale(value: unknown): value is Locale {
  return value === "en" || value === "fr";
}

export function parseLocale(value: unknown, fallback: Locale = "en"): Locale {
  return isLocale(value) ? value : fallback;
}

/**
 * Home-size options for the contact form. `value` is the canonical string
 * stored in the database and shown to reps in WordPress (always English, for
 * consistent reporting); `en`/`fr` are display labels only.
 */
export const HOME_SIZES: { value: string; en: string; fr: string }[] = [
  { value: "Studio", en: "Studio", fr: "Studio" },
  { value: "1 bedroom", en: "1 bedroom", fr: "1 chambre" },
  { value: "2 bedrooms", en: "2 bedrooms", fr: "2 chambres" },
  { value: "3 bedrooms", en: "3 bedrooms", fr: "3 chambres" },
  { value: "4+ bedrooms", en: "4+ bedrooms", fr: "4 chambres et plus" },
  { value: "House", en: "House", fr: "Maison" },
  { value: "Storage unit", en: "Storage unit", fr: "Unité d’entreposage" },
];

type Messages = {
  pageTitle: string;
  startingCamera: string;
  recordingSolo: string;
  connectedWith: (repEmail: string) => string;
  reconnecting: string;
  callFailedStillRecording: string;
  waitingForRep: (repEmail: string) => string;
  detailsSentTo: (repEmail: string) => string;
  showTheRoom: string;
  showMyFace: string;
  sending: string;
  endAndSend: string;
  recordingIndicator: string;
  sendingWalkthrough: string;
  dragToPoint: string;
  doneTitle: string;
  doneBody: (repEmail: string) => string;
  errorTitle: string;
  somethingWrong: string;
  tryAgain: string;
  cameraHttpsError: string;
  recorderFailedError: string;
  nothingRecordedError: string;
  uploadFailedError: string;
  couldNotUploadError: string;
  couldNotStartCameraError: string;
  recordingStoppedError: string;
  switchCameraError: (detail: string) => string;
  couldNotSendDetailsError: string;
  contactTitle: string;
  contactIntro: string;
  namePlaceholder: string;
  phonePlaceholder: string;
  emailPlaceholder: string;
  moveDateLabel: string;
  homeSizeLabel: string;
  homeSizeChoose: string;
  currentAddressPlaceholder: string;
  destinationAddressPlaceholder: string;
  notePlaceholder: string;
  nameRequiredError: string;
  couldNotSendError: string;
  notNow: string;
  sendToRep: string;
  linkNotValidTitle: string;
  linkNotValidBody: string;
};

export const MESSAGES: Record<Locale, Messages> = {
  en: {
    pageTitle: "Live walkthrough",
    startingCamera: "Starting your camera…",
    recordingSolo: "Recording your walkthrough",
    connectedWith: (repEmail) => `Connected with ${repEmail}`,
    reconnecting: "Reconnecting…",
    callFailedStillRecording: "Couldn’t reach the call — still recording your walkthrough",
    waitingForRep: (repEmail) => `Waiting for ${repEmail} to join…`,
    detailsSentTo: (repEmail) => `✓ Your details were sent to ${repEmail}.`,
    showTheRoom: "📸 Show the room",
    showMyFace: "🙂 Show my face",
    sending: "Sending…",
    endAndSend: "End & send",
    recordingIndicator: "🔴 Recording",
    sendingWalkthrough: "📤 Sending your walkthrough…",
    dragToPoint: "Drag on the video to point. This call is recorded for your estimate.",
    doneTitle: "Thanks — your walkthrough is in.",
    doneBody: (repEmail) =>
      `${repEmail} has your recording and will follow up with an estimate. You can close this page.`,
    errorTitle: "The call couldn’t continue",
    somethingWrong: "Something went wrong.",
    tryAgain: "Try again",
    cameraHttpsError:
      "Camera access needs HTTPS. Open this link over HTTPS or use localhost on a computer.",
    recorderFailedError: "The recorder failed to start.",
    nothingRecordedError: "Nothing was recorded.",
    uploadFailedError: "Upload failed.",
    couldNotUploadError: "Could not upload the recording.",
    couldNotStartCameraError: "Couldn’t start the camera or microphone.",
    recordingStoppedError: "Recording stopped unexpectedly. Please end the call and try again.",
    switchCameraError: (detail) => `Couldn’t switch camera${detail}. Staying on the current one.`,
    couldNotSendDetailsError: "Couldn’t send your details.",
    contactTitle: "Your contact details",
    contactIntro:
      "Your rep asked for these so they can follow up with your estimate. Only your name is required.",
    namePlaceholder: "Name *",
    phonePlaceholder: "Phone (optional)",
    emailPlaceholder: "Email (optional)",
    moveDateLabel: "Expected moving date (optional)",
    homeSizeLabel: "Home size (optional)",
    homeSizeChoose: "Choose one…",
    currentAddressPlaceholder: "Current address (optional)",
    destinationAddressPlaceholder: "Destination address (optional)",
    notePlaceholder: "Anything else (optional)",
    nameRequiredError: "Please add your name.",
    couldNotSendError: "Couldn’t send.",
    notNow: "Not now",
    sendToRep: "Send to rep",
    linkNotValidTitle: "This link isn’t valid",
    linkNotValidBody: "It may have expired. Contact your Tom Moving representative for a new one.",
  },
  fr: {
    pageTitle: "Visite en direct",
    startingCamera: "Démarrage de votre caméra…",
    recordingSolo: "Enregistrement de votre visite",
    connectedWith: (repEmail) => `Connecté avec ${repEmail}`,
    reconnecting: "Reconnexion…",
    callFailedStillRecording:
      "Impossible de joindre l’appel — votre visite est tout de même enregistrée",
    waitingForRep: (repEmail) => `En attente que ${repEmail} se joigne…`,
    detailsSentTo: (repEmail) => `✓ Vos coordonnées ont été envoyées à ${repEmail}.`,
    showTheRoom: "📸 Montrer la pièce",
    showMyFace: "🙂 Montrer mon visage",
    sending: "Envoi…",
    endAndSend: "Terminer et envoyer",
    recordingIndicator: "🔴 Enregistrement",
    sendingWalkthrough: "📤 Envoi de votre visite…",
    dragToPoint: "Glissez sur la vidéo pour pointer. Cet appel est enregistré pour votre estimation.",
    doneTitle: "Merci — votre visite a été envoyée.",
    doneBody: (repEmail) =>
      `${repEmail} a votre enregistrement et communiquera avec vous pour l’estimation. Vous pouvez fermer cette page.`,
    errorTitle: "L’appel n’a pas pu continuer",
    somethingWrong: "Une erreur s’est produite.",
    tryAgain: "Réessayer",
    cameraHttpsError:
      "L’accès à la caméra nécessite HTTPS. Ouvrez ce lien en HTTPS ou utilisez localhost sur un ordinateur.",
    recorderFailedError: "L’enregistreur n’a pas pu démarrer.",
    nothingRecordedError: "Rien n’a été enregistré.",
    uploadFailedError: "Le téléversement a échoué.",
    couldNotUploadError: "Impossible de téléverser l’enregistrement.",
    couldNotStartCameraError: "Impossible d’accéder à la caméra ou au microphone.",
    recordingStoppedError:
      "L’enregistrement s’est arrêté de façon inattendue. Veuillez terminer l’appel et réessayer.",
    switchCameraError: (detail) =>
      `Impossible de changer de caméra${detail}. La caméra actuelle reste active.`,
    couldNotSendDetailsError: "Impossible d’envoyer vos coordonnées.",
    contactTitle: "Vos coordonnées",
    contactIntro:
      "Votre représentant en a besoin pour assurer le suivi de votre estimation. Seul votre nom est requis.",
    namePlaceholder: "Nom *",
    phonePlaceholder: "Téléphone (facultatif)",
    emailPlaceholder: "Courriel (facultatif)",
    moveDateLabel: "Date de déménagement prévue (facultatif)",
    homeSizeLabel: "Taille du logement (facultatif)",
    homeSizeChoose: "Choisissez…",
    currentAddressPlaceholder: "Adresse actuelle (facultatif)",
    destinationAddressPlaceholder: "Adresse de destination (facultatif)",
    notePlaceholder: "Autre chose à préciser (facultatif)",
    nameRequiredError: "Veuillez indiquer votre nom.",
    couldNotSendError: "Impossible d’envoyer.",
    notNow: "Pas maintenant",
    sendToRep: "Envoyer au représentant",
    linkNotValidTitle: "Ce lien n’est pas valide",
    linkNotValidBody:
      "Il est peut-être expiré. Communiquez avec votre représentant Tom Moving pour en obtenir un nouveau.",
  },
};
