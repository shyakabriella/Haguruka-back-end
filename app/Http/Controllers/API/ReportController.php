import { Ionicons, MaterialCommunityIcons } from "@expo/vector-icons";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { router, useFocusEffect, type Href } from "expo-router";
import { StatusBar } from "expo-status-bar";
import { useCallback, useMemo, useRef, useState } from "react";
import {
  ActivityIndicator,
  Alert,
  Animated,
  Easing,
  Linking,
  Modal,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { useLanguage } from "../../Providers/LanguageContext";
import { useReport } from "../../Providers/ReportContext";
import { fetchVictimReports } from "../../services/api";

type LanguageKey = "en" | "rw" | "fr";
type CaseActionMode = "withdraw" | "close" | null;

type EvidenceItem = {
  id: number;
  file_name?: string;
  file_type?: string;
  file_size?: number;
  file_url?: string;
};

type UserLite = {
  id?: number | string;
  name?: string | null;
  email?: string | null;
  phone?: string | null;
};

type AuthUser = UserLite & {
  role?: string | null | { slug?: string | null; name?: string | null };
  role_slug?: string | null;
  user_role?: string | null;
  type?: string | null;
  roles?: Array<string | { slug?: string | null; name?: string | null }>;
};

type FollowUpTaskItem = {
  id: number;
  task_code?: string;
  victim_report_id?: number;
  case_code?: string;
  title?: string | null;
  description?: string | null;
  priority?: string | null;
  status?: string | null;
  due_date?: string | null;
  completed_at?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
  created_by?: number | null;
  assigned_to?: number | null;
  creator?: UserLite | null;
  assignee?: UserLite | null;
};

type AppointmentItem = {
  id: number;
  appointment_code?: string;
  victim_report_id?: number | null;
  case_code?: string;
  client_name?: string | null;
  client_phone?: string | null;
  client_email?: string | null;
  appointment_type?: string | null;
  district?: string | null;
  scheduled_at?: string | null;
  scheduled_date?: string | null;
  scheduled_time?: string | null;
  status?: string | null;
  notes?: string | null;
  assigned_to?: number | null;
  assignee?: UserLite | null;
  creator?: UserLite | null;
  completed_at?: string | null;
  cancelled_at?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
};

type ReportItem = {
  id: number;
  user_id?: number | string | null;
  reporter_user?: UserLite | null;
  language?: string | null;
  reporter_role?: string | null;
  urgency?: string | null;
  case_type?: string | null;
  input_mode?: string | null;
  details?: string | null;
  status?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
  latitude?: number | null;
  longitude?: number | null;
  evidences?: EvidenceItem[];
};

type FetchVictimReportsResponse = {
  data?: {
    data?: ReportItem[];
  };
};

type QuickEmergencyResponse = {
  success?: boolean;
  message?: string;
  data?: ReportItem;
};

type CaseActionResponse = {
  success?: boolean;
  message?: string;
  data?: ReportItem;
};

type FollowUpTasksResponse = {
  success?: boolean;
  message?: string;
  data?: FollowUpTaskItem[] | { data?: FollowUpTaskItem[] };
};

type AppointmentsResponse = {
  success?: boolean;
  message?: string;
  data?: AppointmentItem[] | { data?: AppointmentItem[] };
};

type ProgressStep = {
  key: string;
  label: string;
};

type CopyText = {
  greeting: string;
  subtitle: string;
  reporterId: string;
  anonymous: string;
  language: string;
  role: string;
  totalCases: string;
  urgentCases: string;
  createNew: string;
  trackMyCase: string;
  hideProgress: string;
  logout: string;
  logoutTitle: string;
  logoutMessage: string;
  cancel: string;
  yesLogout: string;
  quickActionsTitle: string;
  caseReports: string;
  noCases: string;
  unknownRole: string;
  unknownLanguage: string;
  unknownValue: string;
  evidence: string;
  evidenceList: string;
  status: string;
  createdAt: string;
  caseType: string;
  urgency: string;
  inputMode: string;
  details: string;
  reportId: string;
  callPolice: string;
  callHaguruka: string;
  close: string;
  reportDetails: string;
  showMore: string;
  showLess: string;
  noDetails: string;
  noEvidence: string;
  replaceHagurukaNumber: string;
  callError: string;
  errorTitle: string;
  quickEmergency: string;
  quickEmergencyTitle: string;
  quickEmergencyMessage: string;
  quickEmergencySuccess: string;
  quickEmergencyFailed: string;
  quickEmergencyDetails: string;
  caseProgress: string;
  currentStage: string;
  stageSubmitted: string;
  stageReview: string;
  stageAction: string;
  stageResolved: string;
  progressHint: string;
  progressClosed: string;
  progressWithdrawn: string;
  progressRejected: string;
  caseManagement: string;
  withdrawCase: string;
  closeCaseAction: string;
  reasonLabel: string;
  reasonPlaceholder: string;
  submitWithdraw: string;
  submitClose: string;
  cancelAction: string;
  reasonRequired: string;
  actionSuccess: string;
  actionFailed: string;
  caseAlreadyFinalized: string;

  adminFollowUp: string;
  adminFollowUpHint: string;
  refreshFollowUp: string;
  loadingFollowUp: string;
  noFollowUp: string;
  followUpLoadFailed: string;
  taskTitle: string;
  taskDescription: string;
  priority: string;
  assignedTo: string;
  createdBy: string;
  dueDate: string;
  completedAt: string;
  updatedAt: string;

  appointments: string;
  appointmentsHint: string;
  refreshAppointments: string;
  loadingAppointments: string;
  noAppointments: string;
  appointmentLoadFailed: string;
  appointmentType: string;
  scheduledAt: string;
  district: string;
  client: string;
  notes: string;

  openEvidence: string;
  evidenceOpenError: string;
};

const COPY: Record<LanguageKey, CopyText> = {
  en: {
    greeting: "Your Case Dashboard",
    subtitle: "Track your submitted cases and create a new report anytime.",
    reporterId: "Reporter Identification",
    anonymous: "Anonymous Reporter",
    language: "Language",
    role: "Reporter Role",
    totalCases: "Total Cases",
    urgentCases: "Urgent Cases",
    createNew: "Create New Report",
    trackMyCase: "Track This Case",
    hideProgress: "Hide Progress",
    logout: "Logout",
    logoutTitle: "Logout",
    logoutMessage: "Are you sure you want to logout?",
    cancel: "Cancel",
    yesLogout: "Yes, logout",
    quickActionsTitle: "Quick Actions",
    caseReports: "Case Reports",
    noCases: "No case reports found yet.",
    unknownRole: "Not provided",
    unknownLanguage: "Not set",
    unknownValue: "Unknown",
    evidence: "Evidence",
    evidenceList: "Evidence Files",
    status: "Status",
    createdAt: "Submitted",
    caseType: "Case Type",
    urgency: "Urgency",
    inputMode: "Input Mode",
    details: "Details",
    reportId: "Report ID",
    callPolice: "Call Police",
    callHaguruka: "Call Haguruka",
    close: "Close",
    reportDetails: "Report Details",
    showMore: "Show More",
    showLess: "Show Less",
    noDetails: "No text details provided.",
    noEvidence: "No evidence attached.",
    replaceHagurukaNumber:
      "Please replace HAGURUKA_PHONE with the real Haguruka number.",
    callError: "Unable to start the phone call on this device.",
    errorTitle: "Error",
    quickEmergency: "Quick Emergency",
    quickEmergencyTitle: "Quick Emergency",
    quickEmergencyMessage:
      "This will submit a fast emergency report immediately. Continue?",
    quickEmergencySuccess: "Emergency report submitted successfully.",
    quickEmergencyFailed: "Failed to submit emergency report.",
    quickEmergencyDetails:
      "Quick emergency alert submitted from mobile dashboard. Victim may be in immediate danger and needs urgent support.",
    caseProgress: "Case Progress",
    currentStage: "Current Stage",
    stageSubmitted: "Submitted",
    stageReview: "Under Review",
    stageAction: "Support / Action",
    stageResolved: "Resolved",
    progressHint:
      "This tracker helps you understand where your case is right now.",
    progressClosed: "This case has been closed.",
    progressWithdrawn: "This case has been withdrawn by the victim.",
    progressRejected: "This case was rejected.",
    caseManagement: "Case Management",
    withdrawCase: "Withdraw Case",
    closeCaseAction: "Close Case",
    reasonLabel: "Reason / Motif",
    reasonPlaceholder: "Write the reason here...",
    submitWithdraw: "Confirm Withdraw",
    submitClose: "Confirm Close",
    cancelAction: "Cancel Action",
    reasonRequired: "Please provide a reason first.",
    actionSuccess: "Case updated successfully.",
    actionFailed: "Failed to update case.",
    caseAlreadyFinalized:
      "This case is already finalized and can no longer be changed.",

    adminFollowUp: "Admin Follow-Up",
    adminFollowUpHint:
      "These are follow-up actions added by Haguruka staff or admin for this case.",
    refreshFollowUp: "Refresh Follow-Up",
    loadingFollowUp: "Loading follow-up tasks...",
    noFollowUp: "No admin follow-up has been added to this case yet.",
    followUpLoadFailed: "Failed to load follow-up tasks.",
    taskTitle: "Task",
    taskDescription: "Description",
    priority: "Priority",
    assignedTo: "Assigned To",
    createdBy: "Created By",
    dueDate: "Due Date",
    completedAt: "Completed At",
    updatedAt: "Updated At",

    appointments: "Appointments",
    appointmentsHint:
      "These are appointments scheduled by admin or staff for this case.",
    refreshAppointments: "Refresh Appointments",
    loadingAppointments: "Loading appointments...",
    noAppointments: "No appointment has been scheduled for this case yet.",
    appointmentLoadFailed: "Failed to load appointments.",
    appointmentType: "Appointment Type",
    scheduledAt: "Scheduled At",
    district: "District",
    client: "Client",
    notes: "Notes",

    openEvidence: "Open Evidence",
    evidenceOpenError: "Unable to open this evidence file.",
  },

  rw: {
    greeting: "Imbonerahamwe ya Raporo",
    subtitle:
      "Reba raporo watanze kandi utangize indi nshya igihe icyo ari cyo cyose.",
    reporterId: "Ibiranga Utanga Raporo",
    anonymous: "Utanga Raporo Utazwi",
    language: "Ururimi",
    role: "Uruhare",
    totalCases: "Raporo Zose",
    urgentCases: "Raporo Zihutirwa",
    createNew: "Tangira Raporo Nshya",
    trackMyCase: "Kurikirana Iyi Raporo",
    hideProgress: "Hisha Uko Igezweho",
    logout: "Sohoka",
    logoutTitle: "Gusohoka",
    logoutMessage: "Uremeza ko ushaka gusohoka?",
    cancel: "Oya",
    yesLogout: "Yego, sohokamo",
    quickActionsTitle: "Ibikorwa Byihuse",
    caseReports: "Raporo zatanzwe",
    noCases: "Nta raporo ziraboneka.",
    unknownRole: "Ntabwo byatanzwe",
    unknownLanguage: "Ntabwo byashyizweho",
    unknownValue: "Ntibizwi",
    evidence: "Ibimenyetso",
    evidenceList: "Dosiye z'Ibimenyetso",
    status: "Imiterere",
    createdAt: "Byoherejwe",
    caseType: "Ubwoko bw'ikibazo",
    urgency: "Ubwihutirwe",
    inputMode: "Uburyo bwo kohereza",
    details: "Ibisobanuro",
    reportId: "Nomero ya Raporo",
    callPolice: "Hamagara Polisi",
    callHaguruka: "Hamagara Haguruka",
    close: "Funga",
    reportDetails: "Ibisobanuro bya Raporo",
    showMore: "Erekana Izindi",
    showLess: "Erekana Nke",
    noDetails: "Nta bisobanuro byanditse byatanzwe.",
    noEvidence: "Nta bimenyetso byometseho.",
    replaceHagurukaNumber:
      "Hindura HAGURUKA_PHONE ushyiremo nimero nyayo ya Haguruka.",
    callError: "Ntibishoboka gutangiza umuhamagaro kuri iki gikoresho.",
    errorTitle: "Ikosa",
    quickEmergency: "Ubutabazi Bwihuse",
    quickEmergencyTitle: "Ubutabazi Bwihuse",
    quickEmergencyMessage:
      "Ibi bihita byohereza raporo y'ubutabazi ako kanya. Ukomeze?",
    quickEmergencySuccess: "Raporo y'ubutabazi yoherejwe neza.",
    quickEmergencyFailed: "Kohereza raporo y'ubutabazi byanze.",
    quickEmergencyDetails:
      "Raporo y'ubutabazi yoherejwe byihuse kuri dashboard. Uwahohotewe ashobora kuba ari mu kaga kandi akeneye ubufasha bwihuse.",
    caseProgress: "Aho Raporo Igeze",
    currentStage: "Icyiciro Iriho",
    stageSubmitted: "Yoherejwe",
    stageReview: "Irimo Gusuzumwa",
    stageAction: "Ubufasha / Igikorwa",
    stageResolved: "Yakemuwe",
    progressHint:
      "Ibi bikwereka neza aho ikibazo cyawe kigeze muri iki gihe.",
    progressClosed: "Iyi raporo yarafunzwe.",
    progressWithdrawn: "Iyi raporo yavanyweho n'uyitanze.",
    progressRejected: "Iyi raporo yaranzwe.",
    caseManagement: "Gucunga Raporo",
    withdrawCase: "Kuramo Raporo",
    closeCaseAction: "Funga Raporo",
    reasonLabel: "Impamvu / Motif",
    reasonPlaceholder: "Andika impamvu hano...",
    submitWithdraw: "Emeza Gukuramo",
    submitClose: "Emeza Gufunga",
    cancelAction: "Hagarika Iki Gikorwa",
    reasonRequired: "Banze ushyireho impamvu mbere.",
    actionSuccess: "Raporo yahinduwe neza.",
    actionFailed: "Guhindura raporo byanze.",
    caseAlreadyFinalized:
      "Iyi raporo yararangiye kandi ntigishobora guhindurwa.",

    adminFollowUp: "Ibikorwa Bikurikira by'Admin",
    adminFollowUpHint:
      "Ibi ni ibikorwa byo gukurikirana byashyizweho n'abakozi ba Haguruka cyangwa admin kuri iyi raporo.",
    refreshFollowUp: "Ongera Ugarure Ibikorwa",
    loadingFollowUp: "Turimo kuzana ibikorwa byo gukurikirana...",
    noFollowUp: "Nta gikorwa cyo gukurikirana admin yashyize kuri iyi raporo.",
    followUpLoadFailed: "Kuzana ibikorwa byo gukurikirana byanze.",
    taskTitle: "Igikorwa",
    taskDescription: "Ibisobanuro",
    priority: "Ubwihutirwe",
    assignedTo: "Yahawe",
    createdBy: "Yashyizweho na",
    dueDate: "Itariki ntarengwa",
    completedAt: "Yarangiye",
    updatedAt: "Yahinduwe",

    appointments: "Amasaha yo Guhura",
    appointmentsHint:
      "Aya ni amasaha yashyizweho na admin cyangwa abakozi kuri iyi raporo.",
    refreshAppointments: "Ongera Ugarure Amasaha",
    loadingAppointments: "Turimo kuzana amasaha...",
    noAppointments: "Nta saha yo guhura yashyizwe kuri iyi raporo.",
    appointmentLoadFailed: "Kuzana amasaha byanze.",
    appointmentType: "Ubwoko bw'Isaha",
    scheduledAt: "Igihe Giteganyijwe",
    district: "Akarere",
    client: "Umukiriya",
    notes: "Inyandiko",

    openEvidence: "Fungura Ikimenyetso",
    evidenceOpenError: "Ntibishoboka gufungura iyi dosiye y'ikimenyetso.",
  },

  fr: {
    greeting: "Tableau de bord des cas",
    subtitle:
      "Suivez vos cas soumis et créez un nouveau signalement à tout moment.",
    reporterId: "Identification du rapporteur",
    anonymous: "Rapporteur anonyme",
    language: "Langue",
    role: "Rôle du rapporteur",
    totalCases: "Total des cas",
    urgentCases: "Cas urgents",
    createNew: "Créer un nouveau signalement",
    trackMyCase: "Suivre ce cas",
    hideProgress: "Masquer le progrès",
    logout: "Déconnexion",
    logoutTitle: "Déconnexion",
    logoutMessage: "Voulez-vous vraiment vous déconnecter ?",
    cancel: "Annuler",
    yesLogout: "Oui, déconnecter",
    quickActionsTitle: "Actions rapides",
    caseReports: "Signalements",
    noCases: "Aucun signalement trouvé pour le moment.",
    unknownRole: "Non renseigné",
    unknownLanguage: "Non défini",
    unknownValue: "Inconnu",
    evidence: "Preuves",
    evidenceList: "Fichiers de preuve",
    status: "Statut",
    createdAt: "Soumis",
    caseType: "Type de cas",
    urgency: "Urgence",
    inputMode: "Mode d'entrée",
    details: "Détails",
    reportId: "ID du signalement",
    callPolice: "Appeler la Police",
    callHaguruka: "Appeler Haguruka",
    close: "Fermer",
    reportDetails: "Détails du signalement",
    showMore: "Afficher Plus",
    showLess: "Afficher Moins",
    noDetails: "Aucun détail textuel fourni.",
    noEvidence: "Aucune preuve jointe.",
    replaceHagurukaNumber:
      "Veuillez remplacer HAGURUKA_PHONE par le vrai numéro de Haguruka.",
    callError: "Impossible de démarrer l'appel sur cet appareil.",
    errorTitle: "Erreur",
    quickEmergency: "Urgence Rapide",
    quickEmergencyTitle: "Urgence Rapide",
    quickEmergencyMessage:
      "Ceci enverra immédiatement un signalement d'urgence. Continuer ?",
    quickEmergencySuccess: "Signalement d'urgence envoyé avec succès.",
    quickEmergencyFailed: "Échec de l'envoi du signalement d'urgence.",
    quickEmergencyDetails:
      "Alerte d'urgence rapide envoyée depuis le tableau de bord mobile. La victime peut être en danger immédiat et a besoin d'une assistance urgente.",
    caseProgress: "Progression du cas",
    currentStage: "Étape actuelle",
    stageSubmitted: "Soumis",
    stageReview: "En révision",
    stageAction: "Soutien / Action",
    stageResolved: "Résolu",
    progressHint:
      "Ce suivi vous aide à comprendre l'état actuel de votre dossier.",
    progressClosed: "Ce dossier a été fermé.",
    progressWithdrawn: "Ce dossier a été retiré par la victime.",
    progressRejected: "Ce dossier a été rejeté.",
    caseManagement: "Gestion du cas",
    withdrawCase: "Retirer le cas",
    closeCaseAction: "Clôturer le cas",
    reasonLabel: "Raison / Motif",
    reasonPlaceholder: "Écrivez la raison ici...",
    submitWithdraw: "Confirmer le retrait",
    submitClose: "Confirmer la clôture",
    cancelAction: "Annuler cette action",
    reasonRequired: "Veuillez d'abord fournir une raison.",
    actionSuccess: "Cas mis à jour avec succès.",
    actionFailed: "Échec de la mise à jour du cas.",
    caseAlreadyFinalized:
      "Ce cas est déjà finalisé et ne peut plus être modifié.",

    adminFollowUp: "Suivi Admin",
    adminFollowUpHint:
      "Voici les actions de suivi ajoutées par le personnel Haguruka ou l'administrateur pour ce cas.",
    refreshFollowUp: "Actualiser le suivi",
    loadingFollowUp: "Chargement des tâches de suivi...",
    noFollowUp: "Aucun suivi admin n'a encore été ajouté à ce cas.",
    followUpLoadFailed: "Échec du chargement des tâches de suivi.",
    taskTitle: "Tâche",
    taskDescription: "Description",
    priority: "Priorité",
    assignedTo: "Assigné à",
    createdBy: "Créé par",
    dueDate: "Date limite",
    completedAt: "Terminé le",
    updatedAt: "Mis à jour le",

    appointments: "Rendez-vous",
    appointmentsHint:
      "Voici les rendez-vous planifiés par l'administrateur ou le personnel pour ce cas.",
    refreshAppointments: "Actualiser les rendez-vous",
    loadingAppointments: "Chargement des rendez-vous...",
    noAppointments: "Aucun rendez-vous n'a encore été planifié pour ce cas.",
    appointmentLoadFailed: "Échec du chargement des rendez-vous.",
    appointmentType: "Type de rendez-vous",
    scheduledAt: "Planifié le",
    district: "District",
    client: "Client",
    notes: "Notes",

    openEvidence: "Ouvrir la preuve",
    evidenceOpenError: "Impossible d'ouvrir ce fichier de preuve.",
  },
};

const BG = "#D3CAE7";
const CARD_BG = "#F4F0FB";
const CARD_BORDER = "#DDD3F3";
const CARD_ACTIVE = "#EEE9FB";
const PURPLE = "#6B46D9";
const TEXT_DARK = "#1A1A1A";
const TEXT_MUTED = "#5C5570";
const GREEN = "#1C9C68";
const ORANGE = "#C86B0A";
const RED = "#C53A3A";
const DANGER = "#C53A3A";
const BLUE = "#1677FF";

const RAW_API_BASE = process.env.EXPO_PUBLIC_API_BASE_URL || "";
const CLEAN_API_BASE = RAW_API_BASE.replace(/\/+$/, "");
const API_BASE = CLEAN_API_BASE
  ? CLEAN_API_BASE.endsWith("/api")
    ? CLEAN_API_BASE
    : `${CLEAN_API_BASE}/api`
  : "";

const API_ROOT = API_BASE.replace(/\/api$/, "");

const INITIAL_VISIBLE_REPORTS = 3;

const HAGURUKA_PHONE = "REPLACE_HAGURUKA_PHONE";
const POLICE_PHONE = "112";

function formatDate(value?: string | null) {
  if (!value) return "—";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleString();
}

function formatRole(role?: string | null, fallback = "Not provided") {
  const map: Record<string, string> = {
    victim: "Victim",
    witness: "Witness",
    family: "Family / Friend",
    other: "Other",
    someone_else: "Someone Else",
    community_leader: "Community Leader",
  };

  return map[role || ""] || role || fallback;
}

function formatLanguage(value?: string | null, fallback = "Not set") {
  const map: Record<string, string> = {
    en: "English",
    rw: "Kinyarwanda",
    fr: "Français",
  };

  return map[value || ""] || value || fallback;
}

function formatCaseType(value?: string | null) {
  const map: Record<string, string> = {
    physical: "Physical",
    sexual: "Sexual",
    emotional: "Emotional",
    economic: "Economic",
    child: "Child",
    other: "Other",
    emergency: "Emergency",
  };

  return map[value || ""] || value || "Unknown";
}

function formatUrgency(value?: string | null) {
  const map: Record<string, string> = {
    urgent: "Urgent",
    high: "High",
    support: "Support",
    medium: "Medium",
    low: "Low",
  };

  return map[value || ""] || value || "Unknown";
}

function formatInputMode(value?: string | null) {
  const map: Record<string, string> = {
    text: "Text",
    media: "Media",
    audio: "Audio",
    quick_emergency: "Quick Emergency",
  };

  return map[value || ""] || value || "Unknown";
}

function formatAppointmentType(value?: string | null) {
  const map: Record<string, string> = {
    phone_call: "Phone Call",
    in_person: "In-Person Meeting",
    isange_referral: "Isange Referral",
    police_referral: "Police Referral",
  };

  return map[value || ""] || value || "Unknown";
}

function normalizeStatus(status?: string | null) {
  return String(status || "").toLowerCase().trim();
}

function isTerminalStatus(status?: string | null) {
  const s = normalizeStatus(status);
  return s === "closed" || s === "withdrawn" || s === "rejected";
}

function getStatusColors(status?: string | null) {
  const s = normalizeStatus(status);

  if (s === "submitted") {
    return { bg: "#E7F6EE", border: "#B8E2CA", text: GREEN };
  }

  if (s === "pending" || s === "under_review") {
    return { bg: "#FFF1E1", border: "#F4D2A8", text: ORANGE };
  }

  if (s === "in_progress" || s === "investigating" || s === "referred") {
    return { bg: "#EAF2FF", border: "#BDD3FF", text: BLUE };
  }

  if (s === "resolved") {
    return { bg: "#E7F6EE", border: "#B8E2CA", text: GREEN };
  }

  if (s === "rejected") {
    return { bg: "#FDEAEA", border: "#F0B9B9", text: RED };
  }

  if (s === "closed") {
    return { bg: "#EAF2FF", border: "#BDD3FF", text: BLUE };
  }

  if (s === "withdrawn") {
    return { bg: "#FFF5F5", border: "#F0CACA", text: RED };
  }

  return { bg: "#EEE9FB", border: "#D4C7F4", text: PURPLE };
}

function getTaskStatusColors(status?: string | null) {
  const s = normalizeStatus(status);

  if (s === "done" || s === "completed") {
    return { bg: "#E7F6EE", border: "#B8E2CA", text: GREEN };
  }

  if (s === "in_progress" || s === "scheduled") {
    return { bg: "#EAF2FF", border: "#BDD3FF", text: BLUE };
  }

  if (s === "cancelled") {
    return { bg: "#FDEAEA", border: "#F0B9B9", text: RED };
  }

  return { bg: "#FFF1E1", border: "#F4D2A8", text: ORANGE };
}

function getPriorityColors(priority?: string | null) {
  const p = String(priority || "").toLowerCase();

  if (p === "high") {
    return { bg: "#FDEAEA", border: "#F0B9B9", text: RED };
  }

  if (p === "medium") {
    return { bg: "#FFF1E1", border: "#F4D2A8", text: ORANGE };
  }

  return { bg: "#EEE9FB", border: "#D4C7F4", text: PURPLE };
}

function formatTaskStatus(value?: string | null) {
  const map: Record<string, string> = {
    pending: "Pending",
    in_progress: "In Progress",
    done: "Done",
    cancelled: "Cancelled",
    scheduled: "Scheduled",
    completed: "Completed",
  };

  return map[value || ""] || value || "Unknown";
}

function formatPriority(value?: string | null) {
  const map: Record<string, string> = {
    high: "High",
    medium: "Medium",
    low: "Low",
  };

  return map[value || ""] || value || "Unknown";
}

function isValidPhoneNumber(value: string) {
  return /^\+?\d+$/.test(String(value || "").trim());
}

function formatFileSize(bytes?: number) {
  if (!bytes || Number.isNaN(Number(bytes))) return "—";
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function getProgressSteps(text: CopyText): ProgressStep[] {
  return [
    { key: "submitted", label: text.stageSubmitted },
    { key: "review", label: text.stageReview },
    { key: "action", label: text.stageAction },
    { key: "resolved", label: text.stageResolved },
  ];
}

function getProgressIndex(status?: string | null, tasks: FollowUpTaskItem[] = []) {
  const s = normalizeStatus(status);

  const hasInProgressTask = tasks.some(
    (task) => normalizeStatus(task.status) === "in_progress"
  );

  const hasPendingTask = tasks.some(
    (task) => normalizeStatus(task.status) === "pending"
  );

  const hasDoneTask = tasks.some((task) => normalizeStatus(task.status) === "done");

  const allFinished =
    tasks.length > 0 &&
    tasks.every((task) =>
      ["done", "cancelled"].includes(normalizeStatus(task.status))
    );

  if (s === "resolved" || s === "closed" || (allFinished && hasDoneTask)) {
    return 3;
  }

  if (
    s === "in_progress" ||
    s === "investigating" ||
    s === "referred" ||
    hasInProgressTask
  ) {
    return 2;
  }

  if (s === "pending" || s === "under_review" || hasPendingTask || tasks.length > 0) {
    return 1;
  }

  return 0;
}

function getProgressMessage(
  status: string | null | undefined,
  text: CopyText,
  tasks: FollowUpTaskItem[]
) {
  const s = normalizeStatus(status);

  if (s === "closed") return text.progressClosed;
  if (s === "withdrawn") return text.progressWithdrawn;
  if (s === "rejected") return text.progressRejected;

  if (tasks.length > 0) {
    const pending = tasks.filter(
      (task) => normalizeStatus(task.status) === "pending"
    ).length;

    const inProgress = tasks.filter(
      (task) => normalizeStatus(task.status) === "in_progress"
    ).length;

    const done = tasks.filter(
      (task) => normalizeStatus(task.status) === "done"
    ).length;

    const cancelled = tasks.filter(
      (task) => normalizeStatus(task.status) === "cancelled"
    ).length;

    return `Follow-up summary: ${tasks.length} task(s). Pending: ${pending}, In progress: ${inProgress}, Done: ${done}, Cancelled: ${cancelled}.`;
  }

  return text.progressHint;
}

function extractFollowUpTasks(result: FollowUpTasksResponse | null) {
  const payload = result?.data;

  if (Array.isArray(payload)) return payload;

  if (payload && !Array.isArray(payload) && Array.isArray(payload.data)) {
    return payload.data;
  }

  return [];
}

function extractAppointments(result: AppointmentsResponse | null) {
  const payload = result?.data;

  if (Array.isArray(payload)) return payload;

  if (payload && !Array.isArray(payload) && Array.isArray(payload.data)) {
    return payload.data;
  }

  return [];
}

function getUserName(user?: UserLite | null, fallback = "Unknown") {
  return user?.name || fallback;
}

function buildEvidenceUrl(fileUrl?: string | null) {
  if (!fileUrl) return "";

  const clean = String(fileUrl).trim();

  if (!clean) return "";

  if (clean.startsWith("http://") || clean.startsWith("https://")) {
    return clean;
  }

  if (clean.startsWith("/storage/")) {
    return `${API_ROOT}${clean}`;
  }

  if (clean.startsWith("storage/")) {
    return `${API_ROOT}/${clean}`;
  }

  return `${API_ROOT}/${clean.replace(/^\/+/, "")}`;
}

function normalizeStoredUser(payload: unknown): AuthUser | null {
  if (!payload || typeof payload !== "object") return null;

  const obj = payload as Record<string, unknown>;

  if (obj.user && typeof obj.user === "object") {
    return normalizeStoredUser(obj.user);
  }

  if (obj.data && typeof obj.data === "object") {
    return normalizeStoredUser(obj.data);
  }

  if (obj.id || obj.email || obj.phone || obj.name) {
    return obj as AuthUser;
  }

  return null;
}

async function getStoredAuthUser(): Promise<AuthUser | null> {
  const possibleKeys = ["auth_user", "user"];

  for (const key of possibleKeys) {
    const raw = await AsyncStorage.getItem(key);

    if (!raw) continue;

    try {
      const parsed = JSON.parse(raw);
      const user = normalizeStoredUser(parsed);

      if (user) return user;
    } catch {
      // Ignore broken local storage value and try the next key.
    }
  }

  return null;
}

function getAuthUserRoles(user?: AuthUser | null) {
  if (!user) return [];

  const roles: string[] = [];

  const addRole = (value: unknown) => {
    if (typeof value === "string" && value.trim()) {
      roles.push(value.toLowerCase().trim());
    }
  };

  addRole(user.role_slug);
  addRole(user.user_role);
  addRole(user.type);

  if (typeof user.role === "string") {
    addRole(user.role);
  }

  if (user.role && typeof user.role === "object") {
    addRole(user.role.slug);
    addRole(user.role.name);
  }

  if (Array.isArray(user.roles)) {
    user.roles.forEach((role) => {
      if (typeof role === "string") {
        addRole(role);
      } else if (role && typeof role === "object") {
        addRole(role.slug);
        addRole(role.name);
      }
    });
  }

  return Array.from(new Set(roles));
}

function authUserCanManageCases(user?: AuthUser | null) {
  const allowedRoles = [
    "admin",
    "super_admin",
    "haguruka_staff",
    "staff",
    "case_manager",
  ];

  return getAuthUserRoles(user).some((role) => allowedRoles.includes(role));
}

function getReportOwnerId(report: ReportItem) {
  return report.user_id ?? report.reporter_user?.id ?? null;
}

function filterReportsForCurrentUser(items: ReportItem[], user: AuthUser | null) {
  if (authUserCanManageCases(user)) {
    return items;
  }

  if (!user?.id) {
    // Safety first: if we cannot identify the logged-in victim, do not show
    // possibly sensitive reports returned by the API.
    return [];
  }

  const currentUserId = String(user.id);

  return items.filter((report) => {
    const ownerId = getReportOwnerId(report);

    if (ownerId === null || ownerId === undefined) {
      return false;
    }

    return String(ownerId) === currentUserId;
  });
}

export default function TabsDashboard() {
  const { language } = useLanguage() as { language: LanguageKey };
  const { resetReport } = useReport() as { resetReport: () => void };

  const text = useMemo(() => COPY[language] || COPY.en, [language]);

  const [reports, setReports] = useState<ReportItem[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [refreshing, setRefreshing] = useState<boolean>(false);
  const [showAllReports, setShowAllReports] = useState<boolean>(false);
  const [selectedReport, setSelectedReport] = useState<ReportItem | null>(null);
  const [modalVisible, setModalVisible] = useState<boolean>(false);
  const [emergencyLoading, setEmergencyLoading] = useState<boolean>(false);
  const [showTracker, setShowTracker] = useState<boolean>(true);
  const [actionMode, setActionMode] = useState<CaseActionMode>(null);
  const [actionReason, setActionReason] = useState<string>("");
  const [actionLoading, setActionLoading] = useState<boolean>(false);

  const [followUpTasksByCaseId, setFollowUpTasksByCaseId] = useState<
    Record<number, FollowUpTaskItem[]>
  >({});
  const [followUpLoading, setFollowUpLoading] = useState<boolean>(false);
  const [followUpError, setFollowUpError] = useState<string>("");

  const [appointmentsByCaseId, setAppointmentsByCaseId] = useState<
    Record<number, AppointmentItem[]>
  >({});
  const [appointmentLoading, setAppointmentLoading] = useState<boolean>(false);
  const [appointmentError, setAppointmentError] = useState<string>("");

  const fadeAnim = useRef(new Animated.Value(0)).current;
  const moveAnim = useRef(new Animated.Value(18)).current;

  const selectedFollowUps = useMemo(() => {
    if (!selectedReport?.id) return [];
    return followUpTasksByCaseId[selectedReport.id] || [];
  }, [followUpTasksByCaseId, selectedReport?.id]);

  const selectedAppointments = useMemo(() => {
    if (!selectedReport?.id) return [];
    return appointmentsByCaseId[selectedReport.id] || [];
  }, [appointmentsByCaseId, selectedReport?.id]);

  const animateIn = useCallback(() => {
    Animated.parallel([
      Animated.timing(fadeAnim, {
        toValue: 1,
        duration: 500,
        easing: Easing.out(Easing.ease),
        useNativeDriver: true,
      }),
      Animated.timing(moveAnim, {
        toValue: 0,
        duration: 600,
        easing: Easing.out(Easing.ease),
        useNativeDriver: true,
      }),
    ]).start();
  }, [fadeAnim, moveAnim]);

  const loadReports = useCallback(
    async (showLoader = true) => {
      try {
        if (showLoader) setLoading(true);

        const res = (await fetchVictimReports(1)) as FetchVictimReportsResponse;
        const items = res?.data?.data || [];
        const currentUser = await getStoredAuthUser();
        const safeItems = filterReportsForCurrentUser(items, currentUser);

        setReports(safeItems);
      } catch (err: unknown) {
        console.log("Dashboard load error =", err);

        const message =
          err instanceof Error ? err.message : "Failed to load reports.";

        Alert.alert(text.errorTitle, message);
      } finally {
        if (showLoader) setLoading(false);
        setRefreshing(false);
      }
    },
    [text.errorTitle]
  );

  const loadCaseFollowUps = useCallback(
    async (caseId: number, force = false) => {
      if (!API_BASE) {
        setFollowUpError("API URL is missing. Please check your .env file.");
        return;
      }

      if (!force && followUpTasksByCaseId[caseId]) {
        return;
      }

      try {
        setFollowUpLoading(true);
        setFollowUpError("");

        const token = await AsyncStorage.getItem("auth_token");

        const response = await fetch(
          `${API_BASE}/victim-reports/${caseId}/follow-up-tasks`,
          {
            method: "GET",
            headers: {
              Accept: "application/json",
              "Content-Type": "application/json",
              ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
          }
        );

        const rawText = await response.text();
        let result: FollowUpTasksResponse | null = null;

        try {
          result = JSON.parse(rawText) as FollowUpTasksResponse;
        } catch {
          result = null;
        }

        if (!response.ok || result?.success === false) {
          throw new Error(result?.message || text.followUpLoadFailed);
        }

        const tasks = extractFollowUpTasks(result);

        setFollowUpTasksByCaseId((prev) => ({
          ...prev,
          [caseId]: tasks,
        }));
      } catch (err: unknown) {
        console.log("Follow-up load error =", err);

        const message =
          err instanceof Error ? err.message : text.followUpLoadFailed;

        setFollowUpError(message);
      } finally {
        setFollowUpLoading(false);
      }
    },
    [followUpTasksByCaseId, text.followUpLoadFailed]
  );

  const loadCaseAppointments = useCallback(
    async (caseId: number, force = false) => {
      if (!API_BASE) {
        setAppointmentError("API URL is missing. Please check your .env file.");
        return;
      }

      if (!force && appointmentsByCaseId[caseId]) {
        return;
      }

      try {
        setAppointmentLoading(true);
        setAppointmentError("");

        const token = await AsyncStorage.getItem("auth_token");

        const response = await fetch(
          `${API_BASE}/appointments?victim_report_id=${caseId}&per_page=100`,
          {
            method: "GET",
            headers: {
              Accept: "application/json",
              "Content-Type": "application/json",
              ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
          }
        );

        const rawText = await response.text();
        let result: AppointmentsResponse | null = null;

        try {
          result = JSON.parse(rawText) as AppointmentsResponse;
        } catch {
          result = null;
        }

        if (!response.ok || result?.success === false) {
          throw new Error(result?.message || text.appointmentLoadFailed);
        }

        const appointments = extractAppointments(result);

        setAppointmentsByCaseId((prev) => ({
          ...prev,
          [caseId]: appointments,
        }));
      } catch (err: unknown) {
        console.log("Appointment load error =", err);

        const message =
          err instanceof Error ? err.message : text.appointmentLoadFailed;

        setAppointmentError(message);
      } finally {
        setAppointmentLoading(false);
      }
    },
    [appointmentsByCaseId, text.appointmentLoadFailed]
  );

  useFocusEffect(
    useCallback(() => {
      animateIn();
      loadReports(true);
    }, [animateIn, loadReports])
  );

  const onRefresh = async () => {
    setRefreshing(true);
    setFollowUpTasksByCaseId({});
    setAppointmentsByCaseId({});
    await loadReports(false);
  };

  const handleLogout = () => {
    Alert.alert(text.logoutTitle, text.logoutMessage, [
      { text: text.cancel, style: "cancel" },
      {
        text: text.yesLogout,
        style: "destructive",
        onPress: async () => {
          try {
            await AsyncStorage.multiRemove([
              "auth_token",
              "auth_user",
              "token",
              "user",
            ]);
          } catch (error) {
            console.log("Logout storage clear error =", error);
          }

          resetReport();

          router.replace("/(auth)/signin" as Href);
        },
      },
    ]);
  };

  const syncUpdatedReport = (updatedReport: ReportItem) => {
    setReports((prev) =>
      prev.map((item) => (item.id === updatedReport.id ? updatedReport : item))
    );

    setSelectedReport(updatedReport);
  };

  const openReportModal = (report: ReportItem) => {
    setSelectedReport(report);
    setModalVisible(true);
    setShowTracker(true);
    setActionMode(null);
    setActionReason("");
    setFollowUpError("");
    setAppointmentError("");

    void loadCaseFollowUps(report.id, true);
    void loadCaseAppointments(report.id, true);
  };

  const closeReportModal = () => {
    setModalVisible(false);
    setSelectedReport(null);
    setShowTracker(true);
    setActionMode(null);
    setActionReason("");
    setFollowUpError("");
    setAppointmentError("");
  };

  const handleOpenEvidence = async (fileUrl?: string | null) => {
    const url = buildEvidenceUrl(fileUrl);

    if (!url) {
      Alert.alert(text.errorTitle, text.evidenceOpenError);
      return;
    }

    try {
      const supported = await Linking.canOpenURL(url);

      if (!supported) {
        Alert.alert(text.errorTitle, text.evidenceOpenError);
        return;
      }

      await Linking.openURL(url);
    } catch (error) {
      console.log("Open evidence error =", error);
      Alert.alert(text.errorTitle, text.evidenceOpenError);
    }
  };

  const handleCall = async (phone: string, label: "haguruka" | "police") => {
    if (!isValidPhoneNumber(phone)) {
      Alert.alert(
        text.errorTitle,
        label === "haguruka" ? text.replaceHagurukaNumber : text.callError
      );

      return;
    }

    try {
      const url = `tel:${phone}`;
      const supported = await Linking.canOpenURL(url);

      if (!supported) {
        Alert.alert(text.errorTitle, text.callError);
        return;
      }

      await Linking.openURL(url);
    } catch (error) {
      console.log("Call error =", error);
      Alert.alert(text.errorTitle, text.callError);
    }
  };

  const submitQuickEmergency = async () => {
    if (!API_BASE) {
      Alert.alert(
        text.errorTitle,
        "API URL is missing. Please check your .env file."
      );

      return;
    }

    try {
      setEmergencyLoading(true);

      const token = await AsyncStorage.getItem("auth_token");

      const response = await fetch(`${API_BASE}/victim-reports/quick-emergency`, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: JSON.stringify({
          language: language || "en",
          details: text.quickEmergencyDetails,
        }),
      });

      const rawText = await response.text();
      let result: QuickEmergencyResponse | null = null;

      try {
        result = JSON.parse(rawText) as QuickEmergencyResponse;
      } catch {
        result = null;
      }

      if (!response.ok || !result?.success) {
        throw new Error(result?.message || text.quickEmergencyFailed);
      }

      await loadReports(false);

      if (result.data) {
        setSelectedReport(result.data);
        setModalVisible(true);
        setShowTracker(true);

        void loadCaseFollowUps(result.data.id, true);
        void loadCaseAppointments(result.data.id, true);
      }

      Alert.alert(
        text.quickEmergencyTitle,
        result.message || text.quickEmergencySuccess
      );
    } catch (err: unknown) {
      console.log("Quick emergency error =", err);

      const message =
        err instanceof Error ? err.message : text.quickEmergencyFailed;

      Alert.alert(text.errorTitle, message);
    } finally {
      setEmergencyLoading(false);
    }
  };

  const handleQuickEmergency = () => {
    Alert.alert(text.quickEmergencyTitle, text.quickEmergencyMessage, [
      { text: text.cancel, style: "cancel" },
      {
        text: text.quickEmergency,
        style: "destructive",
        onPress: submitQuickEmergency,
      },
    ]);
  };

  const startCaseAction = (mode: Exclude<CaseActionMode, null>) => {
    if (!selectedReport) return;

    if (isTerminalStatus(selectedReport.status)) {
      Alert.alert(text.errorTitle, text.caseAlreadyFinalized);
      return;
    }

    setActionMode(mode);
    setActionReason("");
  };

  const submitCaseAction = async () => {
    if (!API_BASE || !selectedReport || !actionMode) {
      return;
    }

    if (!actionReason.trim()) {
      Alert.alert(text.errorTitle, text.reasonRequired);
      return;
    }

    try {
      setActionLoading(true);

      const token = await AsyncStorage.getItem("auth_token");
      const endpoint = `${API_BASE}/victim-reports/${selectedReport.id}/${actionMode}`;

      const response = await fetch(endpoint, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: JSON.stringify({
          reason: actionReason.trim(),
          motif: actionReason.trim(),
        }),
      });

      const rawText = await response.text();
      let result: CaseActionResponse | null = null;

      try {
        result = JSON.parse(rawText) as CaseActionResponse;
      } catch {
        result = null;
      }

      if (!response.ok || !result?.success) {
        throw new Error(result?.message || text.actionFailed);
      }

      if (result.data) {
        syncUpdatedReport(result.data);
      } else {
        await loadReports(false);
      }

      setActionMode(null);
      setActionReason("");

      Alert.alert(text.caseManagement, result?.message || text.actionSuccess);
    } catch (err: unknown) {
      console.log("Case action error =", err);

      const message = err instanceof Error ? err.message : text.actionFailed;

      Alert.alert(text.errorTitle, message);
    } finally {
      setActionLoading(false);
    }
  };

  const urgentCases = reports.filter((r) => {
    const u = String(r?.urgency || "").toLowerCase();
    return u === "urgent" || u === "high";
  }).length;

  const latest = reports[0] || null;

  const visibleReports = showAllReports
    ? reports
    : reports.slice(0, INITIAL_VISIBLE_REPORTS);

  const progressSteps = getProgressSteps(text);
  const progressIndex = getProgressIndex(selectedReport?.status, selectedFollowUps);
  const progressMessage = getProgressMessage(
    selectedReport?.status,
    text,
    selectedFollowUps
  );
  const caseFinalized = isTerminalStatus(selectedReport?.status);

  return (
    <SafeAreaView style={styles.safeArea}>
      <StatusBar style="dark" />

      <Animated.ScrollView
        style={{
          flex: 1,
          opacity: fadeAnim,
          transform: [{ translateY: moveAnim }],
        }}
        contentContainerStyle={styles.scrollContent}
        refreshControl={
          <RefreshControl refreshing={refreshing} onRefresh={onRefresh} />
        }
        showsVerticalScrollIndicator={false}
      >
        <View style={styles.topHeader}>
          <View style={{ flex: 1 }}>
            <Text style={styles.title}>{text.greeting}</Text>
            <Text style={styles.subtitle}>{text.subtitle}</Text>
          </View>

          <Pressable onPress={handleLogout} style={styles.logoutBtn}>
            <Ionicons name="log-out-outline" size={18} color="#fff" />
            <Text style={styles.logoutBtnText}>{text.logout}</Text>
          </Pressable>
        </View>

        <View style={styles.card}>
          <View style={styles.row}>
            <View style={styles.avatar}>
              <Ionicons name="person-outline" size={22} color={PURPLE} />
            </View>

            <View style={{ flex: 1 }}>
              <Text style={styles.cardTitle}>{text.reporterId}</Text>
              <Text style={styles.cardSub}>{text.anonymous}</Text>
            </View>
          </View>

          <View style={styles.metaRow}>
            <View style={styles.metaBox}>
              <Text style={styles.metaLabel}>{text.language}</Text>
              <Text style={styles.metaValue}>
                {formatLanguage(latest?.language, text.unknownLanguage)}
              </Text>
            </View>

            <View style={styles.metaBox}>
              <Text style={styles.metaLabel}>{text.role}</Text>
              <Text style={styles.metaValue}>
                {formatRole(latest?.reporter_role, text.unknownRole)}
              </Text>
            </View>
          </View>
        </View>

        <View style={styles.statsRow}>
          <View style={styles.statBox}>
            <Text style={styles.statNumber}>{reports.length}</Text>
            <Text style={styles.statLabel}>{text.totalCases}</Text>
          </View>

          <View style={styles.statBox}>
            <Text style={styles.statNumber}>{urgentCases}</Text>
            <Text style={styles.statLabel}>{text.urgentCases}</Text>
          </View>
        </View>

        <Text style={styles.sectionTitle}>{text.quickActionsTitle}</Text>

        <View style={styles.actionGrid}>
          <Pressable
            onPress={() => router.push("/(tabs)/new-report" as Href)}
            style={styles.primaryBtn}
          >
            <Ionicons name="add-circle-outline" size={20} color="#fff" />
            <Text style={styles.primaryBtnText}>{text.createNew}</Text>
          </Pressable>

          <Pressable
            onPress={handleQuickEmergency}
            disabled={emergencyLoading}
            style={[styles.dangerBtn, emergencyLoading && styles.disabledBtn]}
          >
            {emergencyLoading ? (
              <ActivityIndicator color="#fff" />
            ) : (
              <>
                <Ionicons name="warning-outline" size={20} color="#fff" />
                <Text style={styles.dangerBtnText}>{text.quickEmergency}</Text>
              </>
            )}
          </Pressable>
        </View>

        <Text style={styles.sectionTitle}>{text.caseReports}</Text>

        {loading ? (
          <View style={styles.loadingWrap}>
            <ActivityIndicator size="large" color={PURPLE} />
          </View>
        ) : reports.length === 0 ? (
          <View style={styles.emptyCard}>
            <MaterialCommunityIcons
              name="file-document-outline"
              size={42}
              color={PURPLE}
            />
            <Text style={styles.emptyText}>{text.noCases}</Text>
          </View>
        ) : (
          <>
            {visibleReports.map((r) => {
              const sc = getStatusColors(r.status);

              return (
                <Pressable
                  key={r.id}
                  style={styles.caseCard}
                  onPress={() => openReportModal(r)}
                >
                  <View style={styles.caseTop}>
                    <Text style={styles.caseType}>
                      {formatCaseType(r.case_type)} • #{r.id}
                    </Text>

                    <View
                      style={[
                        styles.badge,
                        {
                          backgroundColor: sc.bg,
                          borderColor: sc.border,
                        },
                      ]}
                    >
                      <Text style={[styles.badgeText, { color: sc.text }]}>
                        {r.status || "unknown"}
                      </Text>
                    </View>
                  </View>

                  <Text style={styles.caseDate}>
                    {text.createdAt}: {formatDate(r.created_at)}
                  </Text>

                  <Text style={styles.caseDetails} numberOfLines={3}>
                    {r.details?.trim() ? r.details : text.noDetails}
                  </Text>

                  <View style={styles.caseFooter}>
                    <View style={styles.pill}>
                      <Ionicons
                        name="document-attach-outline"
                        size={16}
                        color={PURPLE}
                      />
                      <Text style={styles.pillText}>
                        {text.evidence}: {r.evidences?.length || 0}
                      </Text>
                    </View>

                    <View style={styles.pill}>
                      <Ionicons name="flag-outline" size={16} color={PURPLE} />
                      <Text style={styles.pillText}>
                        {text.status}: {r.status || text.unknownValue}
                      </Text>
                    </View>
                  </View>
                </Pressable>
              );
            })}

            {reports.length > INITIAL_VISIBLE_REPORTS && (
              <Pressable
                style={styles.showMoreBtn}
                onPress={() => setShowAllReports((prev) => !prev)}
              >
                <Text style={styles.showMoreBtnText}>
                  {showAllReports ? text.showLess : text.showMore}
                </Text>

                <Ionicons
                  name={showAllReports ? "chevron-up" : "chevron-down"}
                  size={18}
                  color={PURPLE}
                />
              </Pressable>
            )}
          </>
        )}
      </Animated.ScrollView>

      <Modal
        visible={modalVisible}
        animationType="slide"
        transparent
        onRequestClose={closeReportModal}
      >
        <View style={styles.modalOverlay}>
          <View style={styles.modalCard}>
            <View style={styles.modalHeader}>
              <Text style={styles.modalTitle}>{text.reportDetails}</Text>

              <Pressable onPress={closeReportModal} style={styles.modalCloseBtn}>
                <Ionicons name="close" size={22} color={TEXT_DARK} />
              </Pressable>
            </View>

            <ScrollView
              showsVerticalScrollIndicator={false}
              contentContainerStyle={{ paddingBottom: 12 }}
            >
              <Pressable
                style={styles.trackToggleBtn}
                onPress={() => setShowTracker((prev) => !prev)}
              >
                <MaterialCommunityIcons
                  name="progress-clock"
                  size={20}
                  color="#fff"
                />
                <Text style={styles.trackToggleBtnText}>
                  {showTracker ? text.hideProgress : text.trackMyCase}
                </Text>
              </Pressable>

              {showTracker && (
                <View style={styles.progressCard}>
                  <Text style={styles.progressTitle}>{text.caseProgress}</Text>

                  <Text style={styles.progressSubText}>
                    {text.currentStage}:{" "}
                    <Text style={styles.progressCurrentValue}>
                      {progressSteps[progressIndex]?.label || text.unknownValue}
                    </Text>
                  </Text>

                  <View style={styles.progressStepsWrap}>
                    {progressSteps.map((step, index) => {
                      const completed = index <= progressIndex;

                      return (
                        <View key={step.key} style={styles.progressStepRow}>
                          <View
                            style={[
                              styles.progressDot,
                              completed && styles.progressDotActive,
                              caseFinalized &&
                                selectedReport?.status === "withdrawn" &&
                                index > 0 &&
                                styles.progressDotMuted,
                            ]}
                          />

                          <View style={{ flex: 1 }}>
                            <Text
                              style={[
                                styles.progressStepText,
                                completed && styles.progressStepTextActive,
                              ]}
                            >
                              {step.label}
                            </Text>
                          </View>
                        </View>
                      );
                    })}
                  </View>

                  <Text style={styles.progressHintText}>{progressMessage}</Text>
                </View>
              )}

              <View style={styles.detailGrid}>
                <View style={styles.detailBox}>
                  <Text style={styles.detailLabel}>{text.reportId}</Text>
                  <Text style={styles.detailValue}>
                    #{selectedReport?.id || "—"}
                  </Text>
                </View>

                <View style={styles.detailBox}>
                  <Text style={styles.detailLabel}>{text.status}</Text>
                  <Text style={styles.detailValue}>
                    {selectedReport?.status || text.unknownValue}
                  </Text>
                </View>

                <View style={styles.detailBox}>
                  <Text style={styles.detailLabel}>{text.caseType}</Text>
                  <Text style={styles.detailValue}>
                    {formatCaseType(selectedReport?.case_type)}
                  </Text>
                </View>

                <View style={styles.detailBox}>
                  <Text style={styles.detailLabel}>{text.urgency}</Text>
                  <Text style={styles.detailValue}>
                    {formatUrgency(selectedReport?.urgency)}
                  </Text>
                </View>

                <View style={styles.detailBox}>
                  <Text style={styles.detailLabel}>{text.language}</Text>
                  <Text style={styles.detailValue}>
                    {formatLanguage(
                      selectedReport?.language,
                      text.unknownLanguage
                    )}
                  </Text>
                </View>

                <View style={styles.detailBox}>
                  <Text style={styles.detailLabel}>{text.role}</Text>
                  <Text style={styles.detailValue}>
                    {formatRole(
                      selectedReport?.reporter_role,
                      text.unknownRole
                    )}
                  </Text>
                </View>

                <View style={styles.detailBoxWide}>
                  <Text style={styles.detailLabel}>{text.inputMode}</Text>
                  <Text style={styles.detailValue}>
                    {formatInputMode(selectedReport?.input_mode)}
                  </Text>
                </View>

                <View style={styles.detailBoxWide}>
                  <Text style={styles.detailLabel}>{text.createdAt}</Text>
                  <Text style={styles.detailValue}>
                    {formatDate(selectedReport?.created_at)}
                  </Text>
                </View>
              </View>

              <View style={styles.detailSection}>
                <Text style={styles.detailSectionTitle}>{text.details}</Text>

                <Text style={styles.detailParagraph}>
                  {selectedReport?.details?.trim()
                    ? selectedReport.details
                    : text.noDetails}
                </Text>
              </View>

              <View style={styles.detailSection}>
                <Text style={styles.detailSectionTitle}>
                  {text.evidenceList}
                </Text>

                {selectedReport?.evidences?.length ? (
                  selectedReport.evidences.map((item) => (
                    <View key={item.id} style={styles.evidenceItem}>
                      <Ionicons
                        name="document-outline"
                        size={18}
                        color={PURPLE}
                      />

                      <View style={{ flex: 1 }}>
                        <Text style={styles.evidenceName}>
                          {item.file_name || "file"}
                        </Text>

                        <Text style={styles.evidenceMeta}>
                          {item.file_type || "unknown"} •{" "}
                          {formatFileSize(item.file_size)}
                        </Text>
                      </View>

                      <Pressable
                        style={styles.openEvidenceBtn}
                        onPress={() => handleOpenEvidence(item.file_url)}
                      >
                        <Text style={styles.openEvidenceText}>
                          {text.openEvidence}
                        </Text>
                      </Pressable>
                    </View>
                  ))
                ) : (
                  <Text style={styles.detailParagraph}>{text.noEvidence}</Text>
                )}
              </View>

              <View style={styles.detailSection}>
                <View style={styles.followUpHeaderRow}>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.detailSectionTitle}>
                      {text.adminFollowUp}
                    </Text>
                    <Text style={styles.followUpHint}>
                      {text.adminFollowUpHint}
                    </Text>
                  </View>

                  <Pressable
                    style={styles.followUpRefreshBtn}
                    disabled={!selectedReport || followUpLoading}
                    onPress={() => {
                      if (selectedReport?.id) {
                        void loadCaseFollowUps(selectedReport.id, true);
                      }
                    }}
                  >
                    {followUpLoading ? (
                      <ActivityIndicator color={PURPLE} size="small" />
                    ) : (
                      <Ionicons name="refresh" size={17} color={PURPLE} />
                    )}
                  </Pressable>
                </View>

                {followUpLoading ? (
                  <View style={styles.followUpLoadingBox}>
                    <ActivityIndicator color={PURPLE} />
                    <Text style={styles.followUpLoadingText}>
                      {text.loadingFollowUp}
                    </Text>
                  </View>
                ) : followUpError ? (
                  <View style={styles.followUpErrorBox}>
                    <Ionicons name="alert-circle-outline" size={18} color={RED} />
                    <Text style={styles.followUpErrorText}>{followUpError}</Text>
                  </View>
                ) : selectedFollowUps.length === 0 ? (
                  <View style={styles.followUpEmptyBox}>
                    <MaterialCommunityIcons
                      name="clipboard-text-clock-outline"
                      size={24}
                      color={PURPLE}
                    />
                    <Text style={styles.followUpEmptyText}>
                      {text.noFollowUp}
                    </Text>
                  </View>
                ) : (
                  <View style={styles.followUpList}>
                    {selectedFollowUps.map((task) => {
                      const taskStatusColors = getTaskStatusColors(task.status);
                      const priorityColors = getPriorityColors(task.priority);

                      return (
                        <View key={task.id} style={styles.followUpTaskCard}>
                          <View style={styles.followUpTaskTop}>
                            <View style={{ flex: 1 }}>
                              <Text style={styles.followUpTaskCode}>
                                {task.task_code || `T-${task.id}`}
                              </Text>
                              <Text style={styles.followUpTaskTitle}>
                                {task.title || text.taskTitle}
                              </Text>
                            </View>

                            <View
                              style={[
                                styles.smallBadge,
                                {
                                  backgroundColor: taskStatusColors.bg,
                                  borderColor: taskStatusColors.border,
                                },
                              ]}
                            >
                              <Text
                                style={[
                                  styles.smallBadgeText,
                                  { color: taskStatusColors.text },
                                ]}
                              >
                                {formatTaskStatus(task.status)}
                              </Text>
                            </View>
                          </View>

                          <View style={styles.followUpMetaWrap}>
                            <View
                              style={[
                                styles.followUpMetaPill,
                                {
                                  backgroundColor: priorityColors.bg,
                                  borderColor: priorityColors.border,
                                },
                              ]}
                            >
                              <Text
                                style={[
                                  styles.followUpMetaText,
                                  { color: priorityColors.text },
                                ]}
                              >
                                {text.priority}: {formatPriority(task.priority)}
                              </Text>
                            </View>

                            <View style={styles.followUpMetaPill}>
                              <Text style={styles.followUpMetaText}>
                                {text.assignedTo}:{" "}
                                {getUserName(task.assignee, "Unassigned")}
                              </Text>
                            </View>

                            <View style={styles.followUpMetaPill}>
                              <Text style={styles.followUpMetaText}>
                                {text.createdBy}:{" "}
                                {getUserName(task.creator, "Admin / Staff")}
                              </Text>
                            </View>
                          </View>

                          {task.description?.trim() ? (
                            <View style={styles.followUpDescriptionBox}>
                              <Text style={styles.followUpDescriptionLabel}>
                                {text.taskDescription}
                              </Text>
                              <Text style={styles.followUpDescriptionText}>
                                {task.description}
                              </Text>
                            </View>
                          ) : null}

                          <View style={styles.followUpDatesGrid}>
                            <View style={styles.followUpDateBox}>
                              <Text style={styles.followUpDateLabel}>
                                {text.dueDate}
                              </Text>
                              <Text style={styles.followUpDateValue}>
                                {task.due_date || "—"}
                              </Text>
                            </View>

                            <View style={styles.followUpDateBox}>
                              <Text style={styles.followUpDateLabel}>
                                {text.completedAt}
                              </Text>
                              <Text style={styles.followUpDateValue}>
                                {formatDate(task.completed_at)}
                              </Text>
                            </View>

                            <View style={styles.followUpDateBox}>
                              <Text style={styles.followUpDateLabel}>
                                {text.updatedAt}
                              </Text>
                              <Text style={styles.followUpDateValue}>
                                {formatDate(task.updated_at)}
                              </Text>
                            </View>
                          </View>
                        </View>
                      );
                    })}
                  </View>
                )}
              </View>

              <View style={styles.detailSection}>
                <View style={styles.followUpHeaderRow}>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.detailSectionTitle}>
                      {text.appointments}
                    </Text>
                    <Text style={styles.followUpHint}>
                      {text.appointmentsHint}
                    </Text>
                  </View>

                  <Pressable
                    style={styles.followUpRefreshBtn}
                    disabled={!selectedReport || appointmentLoading}
                    onPress={() => {
                      if (selectedReport?.id) {
                        void loadCaseAppointments(selectedReport.id, true);
                      }
                    }}
                  >
                    {appointmentLoading ? (
                      <ActivityIndicator color={PURPLE} size="small" />
                    ) : (
                      <Ionicons name="refresh" size={17} color={PURPLE} />
                    )}
                  </Pressable>
                </View>

                {appointmentLoading ? (
                  <View style={styles.followUpLoadingBox}>
                    <ActivityIndicator color={PURPLE} />
                    <Text style={styles.followUpLoadingText}>
                      {text.loadingAppointments}
                    </Text>
                  </View>
                ) : appointmentError ? (
                  <View style={styles.followUpErrorBox}>
                    <Ionicons name="alert-circle-outline" size={18} color={RED} />
                    <Text style={styles.followUpErrorText}>
                      {appointmentError}
                    </Text>
                  </View>
                ) : selectedAppointments.length === 0 ? (
                  <View style={styles.followUpEmptyBox}>
                    <MaterialCommunityIcons
                      name="calendar-clock-outline"
                      size={24}
                      color={PURPLE}
                    />
                    <Text style={styles.followUpEmptyText}>
                      {text.noAppointments}
                    </Text>
                  </View>
                ) : (
                  <View style={styles.followUpList}>
                    {selectedAppointments.map((appointment) => {
                      const statusColors = getTaskStatusColors(
                        appointment.status
                      );

                      return (
                        <View
                          key={appointment.id}
                          style={styles.followUpTaskCard}
                        >
                          <View style={styles.followUpTaskTop}>
                            <View style={{ flex: 1 }}>
                              <Text style={styles.followUpTaskCode}>
                                {appointment.appointment_code ||
                                  `APT-${appointment.id}`}
                              </Text>
                              <Text style={styles.followUpTaskTitle}>
                                {formatAppointmentType(
                                  appointment.appointment_type
                                )}
                              </Text>
                            </View>

                            <View
                              style={[
                                styles.smallBadge,
                                {
                                  backgroundColor: statusColors.bg,
                                  borderColor: statusColors.border,
                                },
                              ]}
                            >
                              <Text
                                style={[
                                  styles.smallBadgeText,
                                  { color: statusColors.text },
                                ]}
                              >
                                {formatTaskStatus(appointment.status)}
                              </Text>
                            </View>
                          </View>

                          <View style={styles.followUpMetaWrap}>
                            <View style={styles.followUpMetaPill}>
                              <Text style={styles.followUpMetaText}>
                                {text.scheduledAt}:{" "}
                                {formatDate(appointment.scheduled_at)}
                              </Text>
                            </View>

                            <View style={styles.followUpMetaPill}>
                              <Text style={styles.followUpMetaText}>
                                {text.assignedTo}:{" "}
                                {getUserName(
                                  appointment.assignee,
                                  "Unassigned"
                                )}
                              </Text>
                            </View>

                            <View style={styles.followUpMetaPill}>
                              <Text style={styles.followUpMetaText}>
                                {text.district}:{" "}
                                {appointment.district || "—"}
                              </Text>
                            </View>
                          </View>

                          {appointment.notes?.trim() ? (
                            <View style={styles.followUpDescriptionBox}>
                              <Text style={styles.followUpDescriptionLabel}>
                                {text.notes}
                              </Text>
                              <Text style={styles.followUpDescriptionText}>
                                {appointment.notes}
                              </Text>
                            </View>
                          ) : null}
                        </View>
                      );
                    })}
                  </View>
                )}
              </View>

              <View style={styles.detailSection}>
                <Text style={styles.detailSectionTitle}>
                  {text.caseManagement}
                </Text>

                <View style={styles.caseManageButtons}>
                  <Pressable
                    style={[
                      styles.withdrawBtn,
                      (caseFinalized || actionLoading) && styles.disabledBtn,
                    ]}
                    disabled={caseFinalized || actionLoading}
                    onPress={() => startCaseAction("withdraw")}
                  >
                    <Ionicons name="arrow-undo-outline" size={18} color="#fff" />
                    <Text style={styles.manageBtnText}>
                      {text.withdrawCase}
                    </Text>
                  </Pressable>

                  <Pressable
                    style={[
                      styles.closeCaseBtn,
                      (caseFinalized || actionLoading) && styles.disabledBtn,
                    ]}
                    disabled={caseFinalized || actionLoading}
                    onPress={() => startCaseAction("close")}
                  >
                    <Ionicons
                      name="checkmark-done-outline"
                      size={18}
                      color="#fff"
                    />
                    <Text style={styles.manageBtnText}>
                      {text.closeCaseAction}
                    </Text>
                  </Pressable>
                </View>

                {actionMode && (
                  <View style={styles.reasonCard}>
                    <Text style={styles.reasonLabel}>{text.reasonLabel}</Text>

                    <TextInput
                      value={actionReason}
                      onChangeText={setActionReason}
                      placeholder={text.reasonPlaceholder}
                      placeholderTextColor="#8A819E"
                      multiline
                      textAlignVertical="top"
                      style={styles.reasonInput}
                    />

                    <View style={styles.reasonActionsRow}>
                      <Pressable
                        style={styles.reasonCancelBtn}
                        onPress={() => {
                          setActionMode(null);
                          setActionReason("");
                        }}
                        disabled={actionLoading}
                      >
                        <Text style={styles.reasonCancelBtnText}>
                          {text.cancelAction}
                        </Text>
                      </Pressable>

                      <Pressable
                        style={styles.reasonSubmitBtn}
                        onPress={submitCaseAction}
                        disabled={actionLoading}
                      >
                        {actionLoading ? (
                          <ActivityIndicator color="#fff" />
                        ) : (
                          <Text style={styles.reasonSubmitBtnText}>
                            {actionMode === "withdraw"
                              ? text.submitWithdraw
                              : text.submitClose}
                          </Text>
                        )}
                      </Pressable>
                    </View>
                  </View>
                )}
              </View>

              <View style={styles.modalActions}>
                <Pressable
                  style={styles.modalHagurukaBtn}
                  onPress={() => handleCall(HAGURUKA_PHONE, "haguruka")}
                >
                  <Ionicons name="call-outline" size={18} color="#fff" />
                  <Text style={styles.modalActionText}>
                    {text.callHaguruka}
                  </Text>
                </Pressable>

                <Pressable
                  style={styles.modalPoliceBtn}
                  onPress={() => handleCall(POLICE_PHONE, "police")}
                >
                  <Ionicons name="shield-outline" size={18} color="#fff" />
                  <Text style={styles.modalActionText}>{text.callPolice}</Text>
                </Pressable>
              </View>

              <Pressable style={styles.closeBtn} onPress={closeReportModal}>
                <Text style={styles.closeBtnText}>{text.close}</Text>
              </Pressable>
            </ScrollView>
          </View>
        </View>
      </Modal>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: BG },
  scrollContent: { padding: 18, paddingBottom: 30 },

  topHeader: {
    flexDirection: "row",
    alignItems: "flex-start",
    justifyContent: "space-between",
    gap: 12,
  },

  title: {
    fontSize: 28,
    fontWeight: "700",
    color: TEXT_DARK,
  },

  subtitle: {
    marginTop: 8,
    color: TEXT_MUTED,
    lineHeight: 20,
  },

  logoutBtn: {
    height: 40,
    borderRadius: 12,
    backgroundColor: "#B9460B",
    paddingHorizontal: 12,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 6,
  },

  logoutBtnText: {
    color: "#fff",
    fontWeight: "700",
    fontSize: 13,
  },

  card: {
    marginTop: 14,
    backgroundColor: CARD_BG,
    borderColor: CARD_BORDER,
    borderWidth: 1,
    borderRadius: 18,
    padding: 14,
  },

  row: {
    flexDirection: "row",
    alignItems: "center",
  },

  avatar: {
    width: 46,
    height: 46,
    borderRadius: 23,
    backgroundColor: "#ECE4FA",
    alignItems: "center",
    justifyContent: "center",
    marginRight: 12,
  },

  cardTitle: {
    fontWeight: "700",
    color: TEXT_DARK,
    fontSize: 16,
  },

  cardSub: {
    color: TEXT_MUTED,
    marginTop: 2,
  },

  metaRow: {
    flexDirection: "row",
    gap: 10,
    marginTop: 12,
  },

  metaBox: {
    flex: 1,
    backgroundColor: "#fff",
    borderColor: CARD_BORDER,
    borderWidth: 1,
    borderRadius: 14,
    padding: 10,
  },

  metaLabel: {
    color: TEXT_MUTED,
    fontSize: 12,
  },

  metaValue: {
    marginTop: 6,
    fontWeight: "600",
    color: TEXT_DARK,
  },

  statsRow: {
    flexDirection: "row",
    gap: 10,
    marginTop: 12,
  },

  statBox: {
    flex: 1,
    backgroundColor: "#fff",
    borderColor: CARD_BORDER,
    borderWidth: 1,
    borderRadius: 16,
    padding: 14,
    alignItems: "center",
  },

  statNumber: {
    fontSize: 26,
    fontWeight: "800",
    color: PURPLE,
  },

  statLabel: {
    marginTop: 6,
    color: TEXT_MUTED,
  },

  sectionTitle: {
    marginTop: 18,
    fontSize: 18,
    fontWeight: "700",
    color: TEXT_DARK,
  },

  actionGrid: {
    marginTop: 12,
    gap: 10,
  },

  primaryBtn: {
    height: 54,
    borderRadius: 15,
    backgroundColor: PURPLE,
    alignItems: "center",
    justifyContent: "center",
    flexDirection: "row",
    gap: 8,
  },

  primaryBtnText: {
    color: "#fff",
    fontWeight: "700",
    fontSize: 16,
  },

  dangerBtn: {
    height: 54,
    borderRadius: 15,
    backgroundColor: DANGER,
    alignItems: "center",
    justifyContent: "center",
    flexDirection: "row",
    gap: 8,
  },

  dangerBtnText: {
    color: "#fff",
    fontWeight: "800",
    fontSize: 16,
  },

  disabledBtn: {
    opacity: 0.7,
  },

  loadingWrap: {
    height: 160,
    alignItems: "center",
    justifyContent: "center",
  },

  emptyCard: {
    marginTop: 14,
    backgroundColor: CARD_BG,
    borderColor: CARD_BORDER,
    borderWidth: 1,
    borderRadius: 18,
    padding: 24,
    alignItems: "center",
  },

  emptyText: {
    marginTop: 10,
    color: TEXT_DARK,
    fontWeight: "700",
  },

  caseCard: {
    marginTop: 12,
    backgroundColor: CARD_BG,
    borderColor: CARD_BORDER,
    borderWidth: 1,
    borderRadius: 18,
    padding: 14,
  },

  caseTop: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
  },

  caseType: {
    fontWeight: "700",
    color: TEXT_DARK,
    flex: 1,
    marginRight: 10,
  },

  badge: {
    borderWidth: 1,
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 6,
  },

  badgeText: {
    fontWeight: "700",
    fontSize: 12,
    textTransform: "capitalize",
  },

  smallBadge: {
    borderWidth: 1,
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 6,
  },

  smallBadgeText: {
    fontWeight: "800",
    fontSize: 11,
  },

  caseDate: {
    marginTop: 8,
    color: TEXT_MUTED,
    fontSize: 12,
  },

  caseDetails: {
    marginTop: 10,
    color: TEXT_DARK,
    lineHeight: 20,
  },

  caseFooter: {
    marginTop: 12,
    flexDirection: "row",
    gap: 8,
    flexWrap: "wrap",
  },

  pill: {
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    backgroundColor: CARD_ACTIVE,
    borderColor: CARD_BORDER,
    borderWidth: 1,
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 7,
  },

  pillText: {
    color: PURPLE,
    fontWeight: "600",
    fontSize: 12,
  },

  showMoreBtn: {
    marginTop: 14,
    height: 48,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: CARD_BORDER,
    backgroundColor: "#fff",
    alignItems: "center",
    justifyContent: "center",
    flexDirection: "row",
    gap: 6,
  },

  showMoreBtnText: {
    color: PURPLE,
    fontWeight: "700",
    fontSize: 15,
  },

  modalOverlay: {
    flex: 1,
    backgroundColor: "rgba(20,20,20,0.35)",
    justifyContent: "flex-end",
  },

  modalCard: {
    maxHeight: "90%",
    backgroundColor: "#fff",
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    paddingHorizontal: 18,
    paddingTop: 16,
    paddingBottom: 18,
  },

  modalHeader: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    marginBottom: 12,
  },

  modalTitle: {
    fontSize: 20,
    fontWeight: "800",
    color: TEXT_DARK,
  },

  modalCloseBtn: {
    width: 38,
    height: 38,
    borderRadius: 19,
    backgroundColor: "#F1EDF9",
    alignItems: "center",
    justifyContent: "center",
  },

  trackToggleBtn: {
    height: 50,
    borderRadius: 14,
    backgroundColor: PURPLE,
    alignItems: "center",
    justifyContent: "center",
    flexDirection: "row",
    gap: 8,
    marginBottom: 12,
  },

  trackToggleBtnText: {
    color: "#fff",
    fontWeight: "800",
    fontSize: 15,
  },

  progressCard: {
    backgroundColor: "#F8F6FC",
    borderWidth: 1,
    borderColor: CARD_BORDER,
    borderRadius: 16,
    padding: 14,
    marginBottom: 12,
  },

  progressTitle: {
    fontSize: 17,
    fontWeight: "800",
    color: TEXT_DARK,
  },

  progressSubText: {
    marginTop: 6,
    color: TEXT_MUTED,
  },

  progressCurrentValue: {
    color: PURPLE,
    fontWeight: "800",
  },

  progressStepsWrap: {
    marginTop: 14,
    gap: 10,
  },

  progressStepRow: {
    flexDirection: "row",
    alignItems: "center",
  },

  progressDot: {
    width: 14,
    height: 14,
    borderRadius: 7,
    backgroundColor: "#D8D1E9",
    marginRight: 12,
  },

  progressDotActive: {
    backgroundColor: PURPLE,
  },

  progressDotMuted: {
    backgroundColor: "#E7E2F4",
  },

  progressStepText: {
    color: TEXT_MUTED,
    fontWeight: "600",
  },

  progressStepTextActive: {
    color: TEXT_DARK,
    fontWeight: "800",
  },

  progressHintText: {
    marginTop: 12,
    color: TEXT_MUTED,
    lineHeight: 19,
  },

  detailGrid: {
    gap: 10,
  },

  detailBox: {
    backgroundColor: "#F8F6FC",
    borderWidth: 1,
    borderColor: CARD_BORDER,
    borderRadius: 14,
    padding: 12,
  },

  detailBoxWide: {
    backgroundColor: "#F8F6FC",
    borderWidth: 1,
    borderColor: CARD_BORDER,
    borderRadius: 14,
    padding: 12,
  },

  detailLabel: {
    color: TEXT_MUTED,
    fontSize: 12,
    marginBottom: 6,
  },

  detailValue: {
    color: TEXT_DARK,
    fontWeight: "700",
  },

  detailSection: {
    marginTop: 14,
    backgroundColor: "#F8F6FC",
    borderWidth: 1,
    borderColor: CARD_BORDER,
    borderRadius: 14,
    padding: 12,
  },

  detailSectionTitle: {
    fontWeight: "800",
    color: TEXT_DARK,
    marginBottom: 8,
  },

  detailParagraph: {
    color: TEXT_DARK,
    lineHeight: 20,
  },

  evidenceItem: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: 10,
    paddingVertical: 8,
    borderBottomWidth: 1,
    borderBottomColor: "#ECE7F7",
  },

  evidenceName: {
    color: TEXT_DARK,
    fontWeight: "700",
  },

  evidenceMeta: {
    marginTop: 2,
    color: TEXT_MUTED,
    fontSize: 12,
  },

  openEvidenceBtn: {
    borderRadius: 999,
    backgroundColor: PURPLE,
    paddingHorizontal: 10,
    paddingVertical: 7,
  },

  openEvidenceText: {
    color: "#fff",
    fontWeight: "800",
    fontSize: 11,
  },

  followUpHeaderRow: {
    flexDirection: "row",
    alignItems: "flex-start",
    justifyContent: "space-between",
    gap: 10,
  },

  followUpHint: {
    color: TEXT_MUTED,
    fontSize: 12,
    lineHeight: 18,
    marginTop: -3,
    marginBottom: 8,
  },

  followUpRefreshBtn: {
    width: 38,
    height: 38,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: CARD_BORDER,
    backgroundColor: "#fff",
    alignItems: "center",
    justifyContent: "center",
  },

  followUpLoadingBox: {
    minHeight: 70,
    borderRadius: 14,
    backgroundColor: "#FFFFFF",
    borderWidth: 1,
    borderColor: CARD_BORDER,
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
  },

  followUpLoadingText: {
    color: TEXT_MUTED,
    fontWeight: "600",
  },

  followUpErrorBox: {
    borderRadius: 14,
    backgroundColor: "#FFF5F5",
    borderWidth: 1,
    borderColor: "#F0CACA",
    padding: 12,
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
  },

  followUpErrorText: {
    flex: 1,
    color: RED,
    fontWeight: "700",
    lineHeight: 18,
  },

  followUpEmptyBox: {
    borderRadius: 14,
    backgroundColor: "#FFFFFF",
    borderWidth: 1,
    borderColor: CARD_BORDER,
    padding: 14,
    alignItems: "center",
    justifyContent: "center",
  },

  followUpEmptyText: {
    marginTop: 8,
    color: TEXT_MUTED,
    fontWeight: "600",
    textAlign: "center",
    lineHeight: 18,
  },

  followUpList: {
    gap: 12,
  },

  followUpTaskCard: {
    borderRadius: 16,
    borderWidth: 1,
    borderColor: CARD_BORDER,
    backgroundColor: "#FFFFFF",
    padding: 12,
  },

  followUpTaskTop: {
    flexDirection: "row",
    alignItems: "flex-start",
    justifyContent: "space-between",
    gap: 10,
  },

  followUpTaskCode: {
    color: PURPLE,
    fontWeight: "900",
    fontSize: 12,
  },

  followUpTaskTitle: {
    marginTop: 3,
    color: TEXT_DARK,
    fontWeight: "900",
    lineHeight: 20,
  },

  followUpMetaWrap: {
    marginTop: 10,
    flexDirection: "row",
    flexWrap: "wrap",
    gap: 8,
  },

  followUpMetaPill: {
    borderWidth: 1,
    borderColor: CARD_BORDER,
    backgroundColor: CARD_ACTIVE,
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 7,
  },

  followUpMetaText: {
    color: PURPLE,
    fontWeight: "700",
    fontSize: 12,
  },

  followUpDescriptionBox: {
    marginTop: 10,
    borderRadius: 12,
    backgroundColor: "#FAF8FE",
    borderWidth: 1,
    borderColor: "#ECE7F7",
    padding: 10,
  },

  followUpDescriptionLabel: {
    color: TEXT_MUTED,
    fontSize: 12,
    fontWeight: "800",
    marginBottom: 5,
  },

  followUpDescriptionText: {
    color: TEXT_DARK,
    lineHeight: 19,
  },

  followUpDatesGrid: {
    marginTop: 10,
    gap: 8,
  },

  followUpDateBox: {
    borderRadius: 12,
    backgroundColor: "#FAF8FE",
    borderWidth: 1,
    borderColor: "#ECE7F7",
    padding: 10,
  },

  followUpDateLabel: {
    color: TEXT_MUTED,
    fontSize: 12,
    marginBottom: 4,
  },

  followUpDateValue: {
    color: TEXT_DARK,
    fontWeight: "700",
  },

  caseManageButtons: {
    gap: 10,
    marginTop: 8,
  },

  withdrawBtn: {
    height: 48,
    borderRadius: 14,
    backgroundColor: "#B45309",
    alignItems: "center",
    justifyContent: "center",
    flexDirection: "row",
    gap: 8,
  },

  closeCaseBtn: {
    height: 48,
    borderRadius: 14,
    backgroundColor: "#0F766E",
    alignItems: "center",
    justifyContent: "center",
    flexDirection: "row",
    gap: 8,
  },

  manageBtnText: {
    color: "#fff",
    fontWeight: "800",
    fontSize: 15,
  },

  reasonCard: {
    marginTop: 12,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: CARD_BORDER,
    backgroundColor: "#FFFFFF",
    padding: 12,
  },

  reasonLabel: {
    color: TEXT_DARK,
    fontWeight: "700",
    marginBottom: 8,
  },

  reasonInput: {
    minHeight: 100,
    borderWidth: 1,
    borderColor: CARD_BORDER,
    borderRadius: 12,
    paddingHorizontal: 12,
    paddingVertical: 10,
    color: TEXT_DARK,
    backgroundColor: "#FAF8FE",
  },

  reasonActionsRow: {
    marginTop: 12,
    gap: 10,
  },

  reasonCancelBtn: {
    height: 46,
    borderRadius: 12,
    backgroundColor: "#EFEAFB",
    alignItems: "center",
    justifyContent: "center",
  },

  reasonCancelBtnText: {
    color: PURPLE,
    fontWeight: "700",
  },

  reasonSubmitBtn: {
    height: 46,
    borderRadius: 12,
    backgroundColor: PURPLE,
    alignItems: "center",
    justifyContent: "center",
  },

  reasonSubmitBtnText: {
    color: "#fff",
    fontWeight: "800",
  },

  modalActions: {
    marginTop: 16,
    gap: 10,
  },

  modalHagurukaBtn: {
    height: 50,
    borderRadius: 14,
    backgroundColor: PURPLE,
    alignItems: "center",
    justifyContent: "center",
    flexDirection: "row",
    gap: 8,
  },

  modalPoliceBtn: {
    height: 50,
    borderRadius: 14,
    backgroundColor: "#0E7490",
    alignItems: "center",
    justifyContent: "center",
    flexDirection: "row",
    gap: 8,
  },

  modalActionText: {
    color: "#fff",
    fontWeight: "800",
    fontSize: 15,
  },

  closeBtn: {
    marginTop: 12,
    height: 48,
    borderRadius: 14,
    backgroundColor: "#EFEAFB",
    alignItems: "center",
    justifyContent: "center",
  },

  closeBtnText: {
    color: PURPLE,
    fontWeight: "800",
    fontSize: 15,
  },
});