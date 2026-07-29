import sys
import io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')

import os
import re
import json
from datetime import datetime

# ── Tesseract ──────────────────────────────────────────────────────────────────
try:
    import pytesseract
    from PIL import Image
except ImportError:
    sys.exit(json.dumps({"error": "pytesseract/Pillow manquant : pip install pytesseract pillow"},
                        ensure_ascii=False))


def _trouver_tesseract() -> str:
    candidats = [
        r'C:\Program Files\Tesseract-OCR\tesseract.exe',
        r'C:\Program Files (x86)\Tesseract-OCR\tesseract.exe',
        '/usr/bin/tesseract',
        '/usr/local/bin/tesseract',
    ]
    for c in candidats:
        if os.path.isfile(c):
            return c
    return 'tesseract'   # dans le PATH


pytesseract.pytesseract.tesseract_cmd = _trouver_tesseract()

# ── pdf2image ──────────────────────────────────────────────────────────────────
try:
    from pdf2image import convert_from_path
    PDF_SUPPORT = True
except ImportError:
    PDF_SUPPORT = False

_POPPLER_CANDIDATES = [
    r'C:\Users\HP\Downloads\Release-26.02.0-0\poppler-26.02.0\Library\bin',
    r'C:\Program Files\poppler\Library\bin',
    r'C:\Program Files\poppler\bin',
    r'C:\poppler\Library\bin',
    r'C:\poppler\bin',
]
POPPLER_PATH = next((p for p in _POPPLER_CANDIDATES if os.path.isdir(p)), None)
MAX_PDF_PAGES = 8

# ── Tables ─────────────────────────────────────────────────────────────────────
MOIS_FR: dict[str, str] = {
    'janvier': '01', 'fevrier': '02', 'février': '02', 'mars': '03',
    'avril': '04', 'mai': '05', 'juin': '06', 'juillet': '07',
    'aout': '08', 'août': '08', 'septembre': '09',
    'octobre': '10', 'novembre': '11', 'decembre': '12', 'décembre': '12',
}
CHIFFRES_FR: dict[str, int] = {
    'un': 1, 'une': 1, 'deux': 2, 'trois': 3, 'quatre': 4, 'cinq': 5,
    'six': 6, 'sept': 7, 'huit': 8, 'neuf': 9, 'dix': 10, 'douze': 12,
}

# Mois arabes (tunisien + standard)
MOIS_AR: dict[str, str] = {
    'يناير': '01', 'جانفي': '01', 'جانفى': '01',
    'فبراير': '02', 'فيفري': '02', 'فيفرى': '02',
    'مارس': '03',
    'أبريل': '04', 'إبريل': '04', 'أفريل': '04',
    'ماي': '05', 'مايو': '05',
    'جوان': '06', 'يونيو': '06',
    'جويلية': '07', 'يوليو': '07',
    'أوت': '08', 'أغسطس': '08',
    'سبتمبر': '09',
    'أكتوبر': '10',
    'نوفمبر': '11',
    'ديسمبر': '12', 'دجنبر': '12',
}

# Villes tunisiennes reconnues (utilisé pour l'adresse fallback et les patterns)
_VILLES_TN = (
    'Mghira', 'Ben Arous', 'La Soukra', 'Lac 1', 'Lac 2', 'Montplaisir',
    'Carthage', 'Tunis', 'Sfax', 'Sousse', 'Nabeul', 'Hammamet',
    'Monastir', 'Mahdia', 'Gafsa', 'Kairouan', 'Bizerte', 'Beja',
    'Jendouba', 'Siliana', 'Zaghouan', 'Kebili', 'Tataouine',
    'Medenine', 'Gabes', 'Tozeur', 'Sidi Bouzid', 'Kasserine',
    'Sidi Bou Said', 'Ariana', 'La Marsa', 'Bardo', 'Manouba',
)
_VILLES_PATTERN = '|'.join(re.escape(v) for v in _VILLES_TN)


# ══════════════════════════════════════════════════════════════════════════════
# OCR
# ══════════════════════════════════════════════════════════════════════════════
def _langs_dispo() -> list[str]:
    try:
        return pytesseract.get_languages()
    except Exception:
        return []


def _choisir_lang() -> str:
    dispo = _langs_dispo()
    if 'ara' in dispo:
        return 'fra+ara+eng'
    return 'fra+eng'


def ocr_fichier(chemin: str, max_pages: int = MAX_PDF_PAGES) -> str:
    lang = _choisir_lang()
    ext  = os.path.splitext(chemin)[1].lower()
    cfg  = "--psm 1 --oem 1"

    if ext == ".pdf":
        if not PDF_SUPPORT:
            sys.exit(json.dumps({"error": "pdf2image manquant : pip install pdf2image"},
                                ensure_ascii=False))
        kwargs: dict = {"dpi": 300, "first_page": 1, "last_page": max_pages}
        if POPPLER_PATH:
            kwargs["poppler_path"] = POPPLER_PATH
        pages = convert_from_path(chemin, **kwargs)
        return "\n--- PAGE ---\n".join(
            pytesseract.image_to_string(p, lang=lang, config=cfg)
            for p in pages
        )

    # Image simple
    return pytesseract.image_to_string(Image.open(chemin), lang=lang, config=cfg)


def normaliser(texte: str) -> str:
    remplacements = [
        ("—", "-"), ("–", "-"), ("\xa0", " "),
        ("\u2019", "'"), ("\u200b", ""), ("\ufeff", ""),
    ]
    for src, dst in remplacements:
        texte = texte.replace(src, dst)
    texte = texte.replace("\r\n", "\n").replace("\r", "\n")
    # Réassembler les mots coupés en fin de ligne
    texte = re.sub(r"-\n(\w)", r"\1", texte)
    # Condenser les lignes vides multiples
    texte = re.sub(r'\n{3,}', '\n\n', texte)
    return texte


# ══════════════════════════════════════════════════════════════════════════════
# Helpers
# ══════════════════════════════════════════════════════════════════════════════
def premier_match(patterns: list[str], texte: str) -> str | None:
    """Retourne le premier groupe(1) non-vide trouvé parmi les patterns."""
    for pat in patterns:
        m = re.search(pat, texte, re.IGNORECASE | re.MULTILINE)
        if m:
            v = m.group(1).strip()
            if v:
                return v
    return None


def normaliser_date(s: str | None) -> str | None:
    """Convertit une date textuelle en YYYY-MM-DD. Retourne None si échec."""
    if not s:
        return None
    s = s.strip().rstrip('.')
    # OCR confond souvent "1er" → "Ier", "Jer", "ler" (L minuscule), "1°", "1º"
    s = re.sub(r'\b[IiJj]er\b', '1er', s)
    s = re.sub(r'\bIER\b', '1er', s, flags=re.IGNORECASE)
    s = re.sub(r'\b1\s*[°º]', '1er', s)  # "1°" ou "1 °" sans word boundary après
    s = re.sub(r'\bler\b', '1er', s)      # "ler" OCR artifact (l minuscule)

    # "premier janvier 2023"
    m = re.match(r'premier\s+(\w+)\s+(\d{4})', s, re.IGNORECASE)
    if m:
        mo = MOIS_FR.get(m.group(1).lower())
        if mo:
            return f"{m.group(2)}-{mo}-01"

    # "15 janvier 2023" ou "1er Septembre 2011"
    m = re.match(r'(\d{1,2})(?:er|ère|[eè]me|e)?\s+(\w+)\s+(\d{4})', s, re.IGNORECASE)
    if m:
        mo = MOIS_FR.get(m.group(2).lower())
        if mo:
            return f"{m.group(3)}-{mo}-{m.group(1).zfill(2)}"

    # "15/01/2023" ou "15-01-23"
    m = re.match(r'(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})', s)
    if m:
        j, mo, a = m.groups()
        a = "20" + a if len(a) == 2 else a
        try:
            return datetime(int(a), int(mo), int(j)).strftime("%Y-%m-%d")
        except ValueError:
            pass

    # Format ISO inversé arabe : "2022/09/01" ou "2023/08/31"
    m = re.match(r'(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$', s)
    if m:
        a, mo, j = m.groups()
        try:
            return datetime(int(a), int(mo), int(j)).strftime("%Y-%m-%d")
        except ValueError:
            pass

    # mois arabe écrit : "31 أوت 2023"
    for ar_mois, mo_num in MOIS_AR.items():
        m_ar = re.match(rf'(\d{{1,2}})\s+{re.escape(ar_mois)}\s+(\d{{4}})', s)
        if m_ar:
            try:
                return f"{m_ar.group(2)}-{mo_num}-{m_ar.group(1).zfill(2)}"
            except Exception:
                pass

    return None


def extraire_montant(s: str | None) -> float | None:
    """
    Convertit une chaîne numérique en float (DT tunisien).
    Gère : séparateurs de milliers (. ou ,), millimes (102.000.000 → 102000 DT).
    """
    if not s:
        return None
    s = re.sub(r'[\s\xa0]', '', s)
    s_clean = re.sub(r'[^\d,\.]', '', s)

    # "20.000" ou "20,000" → 20000
    m = re.match(r'^(\d+)[,\.](\d{3})$', s_clean)
    if m:
        return float(m.group(1) + m.group(2))

    # "102.000.000" (millimes) → 102000.0 DT
    m = re.match(r'^(\d+)\.(\d{3})\.(\d{3})$', s_clean)
    if m:
        val = float(m.group(1) + m.group(2) + "." + m.group(3))
        return round(val / 1000, 3)

    # "1.234.567,89" → format européen complet
    m = re.match(r'^(\d{1,3}(?:\.\d{3})+),(\d{2})$', s_clean)
    if m:
        entier = m.group(1).replace('.', '')
        return float(entier + '.' + m.group(2))

    s_clean = s_clean.replace(',', '.')
    try:
        return float(s_clean)
    except ValueError:
        return None


def _nettoyer_nom(v: str) -> str:
    """Supprime la ponctuation de fin et les espaces superflus."""
    return re.sub(r'[,;:\.\s]+$', '', v).strip()


# ══════════════════════════════════════════════════════════════════════════════
# Extraction principale
# ══════════════════════════════════════════════════════════════════════════════
def extraire_champs(texte: str, zone: str = "Tunisie") -> dict:
    t = texte

    # ── Adresse ───────────────────────────────────────────────────────────────
    adresse = None
    patterns_adresse = [
        r'lotissement\s+(?:N[o°]?\s*)?(\d+[^\n]{5,80})',
        r'(Zone\s+[Ii]ndustrielle[^\n]{3,60})',
        # Priorité : "situé à <ville>" ou "situé à <adresse>" — bien loué
        r'situ[eé]\s+[àa]\s+(?:\w[\w\s\-]{0,20},\s*)?((?:' + _VILLES_PATTERN + r')[^\n]{0,60})',
        r'situ[eé]\s+(?:au|[aà])\s*[:\-]?\s*(\d+\s+[^,\n]{5,80})',
        r'\bsis\s+(?:au|[aà])\s+(.{10,80}?)(?:\n|,)',
        r'adresse\s*[:\-]\s*(.{10,80}?)(?:\n|$)',
        r'Registre\s+[Ff]oncier\s+(?:N[o°]?\s*)?[\d]+[^\n]*?\b([A-Z][a-zÀ-ÿ]+(?:\s+[A-Z][a-zÀ-ÿ]+){1,4})',
        # Fallback ville générique — en dernier recours seulement
        r'(?:local|immeuble|bien)\s+[^.]{0,60}?\b((?:' + _VILLES_PATTERN + r'))\b',
    ]
    # Rejeter les faux positifs d'adresse (phrases débutant par des entités connues)
    _FAUX_ADRESSE_RE = re.compile(
        r'^(?:tunisienne|société|ministère|tunisair|compagnie|immeuble\s+sis)',
        re.IGNORECASE
    )
    for pat in patterns_adresse:
        m = re.search(pat, t, re.IGNORECASE)
        if m:
            candidat = m.group(1).strip().rstrip(',').strip()
            if len(candidat) > 5 and not _FAUX_ADRESSE_RE.match(candidat):
                adresse = candidat
                break

    # Fallback : construire depuis les éléments épars
    if not adresse:
        m_lot = re.search(r'[Ll]otissement\s+(?:N[o°]?\s*)?(\d+)', t)
        m_rs  = re.search(r'(?:RS|R\.S\.|Registre\s+[Ff]oncier)\s*(?:N[o°]?\s*)?(\d{4,6})', t, re.IGNORECASE)
        parts = []
        if m_lot:
            parts.append(f"Lotissement {m_lot.group(1)}")
        if m_rs:
            parts.append(f"RS {m_rs.group(1)}")
        # Priorité : ville mentionnée après "situé à"
        m_sv = re.search(r'situ[eé]\s+[àa]\s+(' + _VILLES_PATTERN + r')', t, re.IGNORECASE)
        if m_sv:
            parts.append(m_sv.group(1))
        else:
            for ville in _VILLES_TN:
                if re.search(r'\b' + re.escape(ville) + r'\b', t, re.IGNORECASE):
                    parts.append(ville)
                    break
        if parts:
            adresse = ", ".join(parts)

    # ── Bailleur ──────────────────────────────────────────────────────────────
    # Fragments OCR à rejeter (valeurs parasitaires récurrentes)
    _BAILLEUR_GARBAGE_RE = re.compile(
        r'(?:loue?\s+[àa]\s+tunisair|loue?\s+au\s+|ci[-\s]?(?:apr[eè]s|dessus)\s+d[eé]sign|'
        r'^d[eé]sign[eé]e?\s*$|^soussign[eé]|ctdessus|'
        r'et\s+domicili[eé]|aéroport\s+tunis)',
        re.IGNORECASE
    )

    # Représentants de TUNISAIR à ne pas confondre avec le bailleur/propriétaire
    _TUNISAIR_REPS: set[str] = set()
    for _rm in re.finditer(
        r'TUNISAIR[^.]{0,120}?(?:repr[eé]sent[eé]e?\s+par\s+(?:son\s+)?'
        r'(?:Pr[eé]sident|secr[eé]taire|Directeur)[^,\n]{0,60}?(?:Monsieur|M\.)\s+([A-Z][A-Za-zÀ-ÿ\s\-]+?)(?:,|\n))',
        t, re.IGNORECASE | re.DOTALL
    ):
        _TUNISAIR_REPS.add(_rm.group(1).strip().upper())

    # Représentants du locataire/Ministère à ne pas confondre avec le bailleur
    # Recherche élargie : "Transport … Monsieur X" sur plusieurs lignes
    _LOCATAIRE_CONTEXT_RE = re.compile(
        r'(?:minist[eè]re|locataire|compte\s+de\s+l.état|transport|l.état)'
        r'[\s\S]{0,150}?(?:Monsieur|M\.)\s+([A-Z][A-Za-zÀ-ÿ]+(?:\s+[A-Z][A-Za-zÀ-ÿ]+){1,3})',
        re.IGNORECASE
    )
    _locataire_rep: set[str] = set()
    for lm in _LOCATAIRE_CONTEXT_RE.finditer(t):
        _locataire_rep.add(lm.group(1).strip().upper())

    def _is_garbage(v: str) -> bool:
        v_up = v.upper()
        if _BAILLEUR_GARBAGE_RE.search(v):
            return True
        # Vérification exacte et partielle (le nom peut être précédé d'un fragment OCR)
        for rep in _TUNISAIR_REPS | _locataire_rep:
            if rep in v_up or v_up in rep:
                return True
        return False

    def _contexte_autour(match_obj, texte_source: str, marge: int = 40) -> str:
        """Extrait ~2*marge caractères autour du match pour vérification manuelle."""
        debut = max(0, match_obj.start() - marge)
        fin = min(len(texte_source), match_obj.end() + marge)
        extrait = texte_source[debut:fin].replace("\n", " ").strip()
        return re.sub(r'\s{2,}', ' ', extrait)

    bailleur = None
    bailleur_confiance = None   # "haute" / "moyenne" / "basse"
    bailleur_contexte = None    # extrait du texte source autour du match

    # ── Priorité 0 : bailleur arabe "السيد X ، صاحب بطاقة التعريف" ──────────
    # Contrats bilingues TUNISAIR : "السيد عبد القادر غيث ، صاحب بطاقة التعريف عدد 07076691"
    _ar_bail = re.search(
        r'السيد\s+([؀-ۿ][؀-ۿ\s]{3,50}?)'
        r'\s*[،،،¬،,]\s*صاحب\s+بطاقة',
        t
    )
    if _ar_bail:
        _v_ar = _ar_bail.group(1).strip()
        if (len(_v_ar) > 3 and len(_v_ar) < 60
                and 'TUNISAIR' not in _v_ar.upper()
                and not re.search(r'\d', _v_ar)):
            bailleur = _v_ar
            bailleur_confiance = "haute"
            bailleur_contexte = _contexte_autour(_ar_bail, t)

    # ── Priorité 1 : propriétaire physique avec "demeurant à" ─────────────────
    if not bailleur:
      m = re.search(
        r'(?:M\.|Mme\.?|Monsieur|Madame)\s+'
        r'([A-ZÀÂÉÈÊËÎÏÔÙÛÇ]{2,}(?:\s+[A-ZÀÂÉÈÊËÎÏÔÙÛÇ][A-Za-zÀ-ÿ\-]{1,}){1,3})'
        r'\s+demeurant',
        t, re.IGNORECASE
    )
    if m:
        v = _nettoyer_nom(m.group(1))
        if not _is_garbage(v):
            bailleur = v
            bailleur_confiance = "haute"
            bailleur_contexte = _contexte_autour(m, t)

    # ── Priorité 2 : patterns généraux ───────────────────────────────────────
    if not bailleur:
        patterns_bailleur = [
            # "le bailleur : NOM PRÉNOM" — stoppe sur virgule ou fin de ligne
            r'(?:le\s+bailleur|المسوغ|bailleur|المؤجر)\s*[:\-]\s*([A-ZÀÂÉÈÊËÎÏÔÙÛÇ][A-Za-zÀ-ÿ\-]{1,30}(?:\s+[A-ZÀÂÉÈÊËÎÏÔÙÛÇ][A-Za-zÀ-ÿ\-]{1,30}){0,3})(?:\n|,)',
            # "Société / Compagnie NOM" — max 5 mots, stoppe sur ponctuation/saut
            r'(?:Soci[eé]t[eé]|Compagnie|شركة)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\s\-]{3,50}?)(?:\n|,|\()',
            # "propriétaire : NOM" — uniquement des majuscules (nom propre)
            r'(?:propri[eé]taire|الطرف\s+الأول)\s*[:\-]\s*([A-ZÀÂÉÈÊËÎÏÔÙÛÇ][A-Za-zÀ-ÿ\-]{1,30}(?:\s+[A-ZÀÂÉÈÊËÎÏÔÙÛÇ][A-Za-zÀ-ÿ\-]{1,30}){0,3})(?:\n|,)',
            # "M. / Mme NOM PRÉNOM" sans suite de phrase (pas de verbe après)
            r'(?:M\.|Mme\.?|Monsieur|Madame)\s+([A-ZÀÂÉÈÊËÎÏÔÙÛÇ]{2,}(?:\s+[A-ZÀÂÉÈÊËÎÏÔÙÛÇ][A-Za-zÀ-ÿ\-]{1,25}){0,3})(?=\s*(?:,|\n|né|demeurant|domicilié|CIN|tél|$))',
        ]
        # Les 3 premiers patterns citent explicitement "bailleur"/"propriétaire"/"société"
        # → confiance haute. Le dernier (M./Mme générique sans mot-clé) est plus risqué
        # de confondre avec un témoin, un garant, etc. → confiance basse.
        for _idx_pat, pat in enumerate(patterns_bailleur):
            m = re.search(pat, t, re.IGNORECASE | re.MULTILINE)
            if m:
                v = _nettoyer_nom(m.group(1))
                # Rejeter si trop long (phrase) ou contient des mots-outils
                if (len(v) > 3 and len(v) < 80
                        and not _is_garbage(v)
                        and not re.search(
                                r'\b(?:donne|location|loue|qui|que|pour|avec|dans|tunisair|'
                                r'solvable|physique|moral|contractant|suivant|présent|present|'
                                r'soussign|désign|désigné|ci-apr|susvis|infra)\b', v, re.IGNORECASE)):
                    bailleur = v
                    bailleur_confiance = "haute" if _idx_pat < 3 else "basse"
                    bailleur_contexte = _contexte_autour(m, t)
                    break

    # ── Priorité 3 : TUNISAIR comme bailleur ─────────────────────────────────
    if not bailleur:
        m3 = re.search(r'TUNISAIR[^.]{0,80}["\']?BAILLEUR["\']?', t, re.IGNORECASE)
        if not m3:
            m3 = re.search(r'TUNISAIR\s+(?:et\s+ou\s+la\s+)?[Pp]ropri[eé]taire', t, re.IGNORECASE)
        if m3:
            bailleur = "TUNISAIR"
            bailleur_confiance = "haute"
            bailleur_contexte = _contexte_autour(m3, t)

    # ── Priorité 4 : fallback société connue ─────────────────────────────────
    # Ce niveau est un dernier recours peu spécifique (aucun mot-clé "bailleur" requis)
    # → confiance systématiquement basse, à vérifier manuellement.
    if not bailleur:
        for pat_soc in [
            r'\bFATH\b[^\n]*',
            r'شركة الفتح[^\n]*',
            r'(?:SA|SARL|SUARL|GIE)\s+[A-Z][A-Za-z\s]{2,40}',
        ]:
            m = re.search(pat_soc, t)
            if m:
                bailleur = _nettoyer_nom(m.group(0))[:60]
                bailleur_confiance = "basse"
                bailleur_contexte = _contexte_autour(m, t)
                break

    if bailleur is None:
        bailleur_confiance = None
        bailleur_contexte = None

    # ── Entité locataire ──────────────────────────────────────────────────────
    entite = None
    # Chercher d'abord TUNISAIR explicitement
    if re.search(r'\bTUNISAIR\b', t):
        entite = "TUNISAIR"
    else:
        m = re.search(
            r'(?:soci[eé]t[eé]|compagnie|entreprise|STEG|SONEDE|ONAS|'
            r'(?:SA|SARL|SUARL|GIE)\s+[A-Z])\s*[\"\«]?([A-ZÀÂÉÈÊËÎÏÔÙÛÇ][^»\"\n]{2,60})',
            t, re.IGNORECASE
        )
        if m:
            entite = _nettoyer_nom(m.group(0))[:60]

    # ── Type de bien ──────────────────────────────────────────────────────────
    types_map = [
        (r'\bbureau(?:x)?\b',                                'Bureau'),
        (r'\bhangar\b',                                      'Hangar'),
        (r'\bappartement\b',                                 'Appartement'),
        (r'\blocal\s+commercial\b',                          'Local commercial'),
        (r'\bterrain\b',                                     'Terrain'),
        (r'\bd[eé]p[oô]t\b|entrepot|entrepôt',              'Dépôt'),
        (r'\bvilla\b',                                       'Villa'),
        (r'\bimmeuble\b',                                    'Immeuble'),
        (r'\bunit[eé]\s+(?:de\s+)?(?:production|stockage|industrielle)\b', 'Unité industrielle'),
        (r'\blocal\s+(?:industriel|industrie)\b',            'Local industriel'),
        (r'مستودع|مخزن',                                     'Dépôt'),
        (r'\bmagasin\b',                                     'Magasin'),
        (r'\batelier\b',                                     'Atelier'),
    ]
    type_bien = None
    for pat, label in types_map:
        if re.search(pat, t, re.IGNORECASE):
            type_bien = label
            break
    if not type_bien and re.search(r'zone\s+industrielle|industri', t, re.IGNORECASE):
        type_bien = 'Local industriel'

    # ── Superficie ────────────────────────────────────────────────────────────
    # Priorité absolue : superficie après avenant ("réduite à X m²" ou "réduire … à X m²")
    superficie = None
    m_reduite = re.search(
        r'r[eé]duire?\s+(?:la\s+)?superficie\s+lou[eé]e\s+[àa]\s+([\d\s]+)\s*m[2²]'
        r'|superficie\s+lou[eé]e\s+[àa]\s+([\d\s]+)\s*m[2²]'
        r'|r[eé]duite?\s+[àa]\s+([\d\s]+)\s*m[2²]',
        t, re.IGNORECASE
    )
    if m_reduite:
        val_str = next(g for g in m_reduite.groups() if g is not None)
        superficie = extraire_montant(val_str.strip())

    if not superficie:
        sup_raw = premier_match([
            r"d[\'e\u2019]environ\s+(\d[\d\s]*)\s*m[2²]",
            r'superficie\s+(?:[Gg]lobale|[Tt]otale|[Jj]mieli[eè]\s+)?(?:de\s+)?([\d\s.,]+)\s*m[2²]',
            r'surface\s+(?:(?:utile|totale|habitable)\s+)?(?:de\s+)?([\d\s.,]+)\s*m[2²]',
            r'([\d\s.,]+)\s*m[2²]\s+(?:de\s+surface|environ|globale)',
            r'([\d]{3,6})\s*(?:م²|متر\s+مربع)',
            r'(\d{3,6})\s*m[2²]',
        ], t)
        superficie = extraire_montant(sup_raw) if sup_raw else None

    # ── Numéro de contrat ─────────────────────────────────────────────────────
    num_contrat = premier_match([
        r'contrat\s*n[o°]?\s*[:\-]?\s*([A-Z0-9][\-\/A-Z0-9]{1,29})',
        r'convention\s*n[o°]?\s*[:\-]?\s*([A-Z0-9][\-\/A-Z0-9]{1,29})',
        r'r[eé]f[eé]rence\s*[:\-]?\s*([A-Z0-9][\-\/A-Z0-9]{1,29})',
        r'(?:RF|RS|R[ôo]le\s+[Ff]oncier)\s*(?:N[o°]?\s*)?(\d{4,6}(?:\s+[A-Z]+\s+\d+)?)',
        # Titres fonciers "n°57.373 et 57.374"
        r'titres?\s+fonciers?\s+n[o°]?\s*([\d\.]+(?:\s+et\s+[\d\.]+)?)',
        # Numéro en haut de page style "0046"
        r'^\s*(0{2,3}\d{2,4})\s*$',
    ], t)

    # ── Dates ─────────────────────────────────────────────────────────────────
    date_debut = None
    # Priorité absolue : "commençant le X et finissant"
    m = re.search(r'commen[cç]ant\s+(?:le\s+)?(.{4,35}?)\s+et\s+finissant', t, re.IGNORECASE)
    if m:
        date_debut = normaliser_date(m.group(1))
    # "prend effet à compter du X" / "Il prend effet à compter du X"
    if not date_debut:
        m = re.search(r'(?:prend\s+effet|[àa]\s+compter)\s+(?:du|[àa]\s+partir\s+du)\s+(.{4,40}?)(?:\.|,|\n|et)', t, re.IGNORECASE)
        if m:
            date_debut = normaliser_date(m.group(1))
    if not date_debut:
        raw = premier_match([
            r'date\s+de\s+d[eé]but\s*[:\-]\s*(.{4,35}?)(?:\.|,|\n)',
            r'entr[eé]e\s+en\s+vigueur\s+(?:le\s+)?(.{4,35}?)(?:\.|,|\n)',
            r'[àa]\s+partir\s+du\s+(.{4,35}?)(?:\.|,|\n)',
            r'commen[cç]ant\s+le\s+(.{4,35}?)(?:\.|,|\n|et)',
            # "du 01/09/2011 au"
            r'(?:du|من|بداية)\s+(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})',
            r'بداية\s+من\s+(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})',
            # Format ISO inversé arabe : "من 2022/09/01"
            r'(?:من|بداية\s+من)\s+(\d{4}\/\d{1,2}\/\d{1,2})',
            # mois arabe écrit : "من 01 سبتمبر 2022"
            r'من\s+(\d{1,2}\s+[\u0600-\u06FF]+\s+\d{4})',
            r'(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})\s*(?:au|إلى|jusqu)',
        ], t)
        date_debut = normaliser_date(raw)

    date_fin = None
    m = re.search(r'finissant\s+le\s+(.{4,35}?)(?:[.,\n])', t, re.IGNORECASE)
    if m:
        date_fin = normaliser_date(m.group(1))
    if not date_fin:
        raw = premier_match([
            r'(?:jusqu[\'u]au|expir[eé]\s+le|[eé]ch[eé]ance\s+le|date\s+de\s+fin)\s*[:\-]?\s*(.{4,35}?)(?:\.|,|\n)',
            r'se\s+termine\s+le\s+(.{4,35}?)(?:\.|,|\n)',
            r'(?:au|إلى|غاية)\s+(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})',
            # Format ISO inversé arabe : "إلى 2023/08/31"
            r'(?:إلى|غاية)\s+(\d{4}\/\d{1,2}\/\d{1,2})',
            # mois arabe écrit : "غاية 31 أوت 2023"
            r'(?:إلى|غاية)\s+(\d{1,2}\s+[\u0600-\u06FF]+\s+\d{4})',
            r'(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})\s*(?:قابلة|renouvelable|renouvellement)',
            # Durée explicite → calcul
        ], t)
        date_fin = normaliser_date(raw)

    # Si durée connue et date_debut mais pas date_fin → calculer
    if date_debut and not date_fin:
        m_dur = re.search(r'dur[eé]e\s+(?:de\s+)?(?:la\s+location\s+)?(?:est\s+(?:de\s+))?(\d+)\s*an', t, re.IGNORECASE)
        if m_dur:
            try:
                d = datetime.strptime(date_debut, "%Y-%m-%d")
                date_fin = d.replace(year=d.year + int(m_dur.group(1))).strftime("%Y-%m-%d")
            except Exception:
                pass

    # ── Loyer / Budget ────────────────────────────────────────────────────────
    budget_annuel = None
    loyer_mensuel = None

    patterns_montant = [
        # Montants avec 2 séparateurs (millimes) : "102.000.000 DT"
        r'\(?([\d]{1,3}(?:[.,]\d{3}){2})\s*(?:د\.ت|DT|TND|dinars?|دينار)',
        # Montants avec 1 séparateur : "8.500 DT"
        r'\(?([\d]{1,3}(?:[.,]\d{3}){1})\s*(?:د\.ت|DT|TND|dinars?|دينار)',
        # Montant entre parenthèses avec espace : "(17 000dinars)" ou "(17 000 dinars)"
        r'\(([\d][\d\s]{2,10})\s*dinars?\)',
        # Montant en chiffres suivi de "dinars" sans parenthèses : "17 000 dinars"
        r'\b([\d]{2,3}\s[\d]{3})\s*dinars?\b',
        # Loyer annuel explicite
        r'loyer\s+annuel\s+(?:de\s+)?([\d\s.,]+)\s*(?:DT|TND|dinars?)',
        r'somme\s+annuelle\s+(?:de\s+)?([\d\s.,]+)\s*(?:DT|TND|dinars?)',
        r'معلوم\s+(?:الكراء\s+)?السنوي\s*[:\-]?\s*([\d\s.,]+)',
        # "fixé à la somme de … (131.000 DT°)" — typo DT° vu dans Montplaisir
        r'fix[eé]\s+[àa]\s+la\s+somme\s+de\s+[^(]{0,80}\(([\d\s.,]+)\s*DT',
        # "loyer est fixé à … (17 000dinars)" sans DT explicite
        r'loyer\s+est\s+fix[eé]\s+[àa]\s+la\s+somme\s+de\s+[^(]{0,80}\(([\d\s]+)\s*dinars?\)',
        # Arabe avenant Montplaisir : "120.000.000 دينار" ou "120,000,000"
        r'([\d]{2,3}[.,]000[,.]000)\s*(?:دينار|dinars?)',
        # Montant global sans unité (fallback)
        r'montant\s+(?:global|total)\s+(?:de\s+)?([\d\s.,]+)\s*(?:DT|TND|dinars?)',
    ]

    montants_trouves = []
    for pat in patterns_montant:
        for m in re.finditer(pat, t, re.IGNORECASE):
            val = extraire_montant(m.group(1))
            if val and val > 0:
                montants_trouves.append(val)

    montants_trouves = sorted(set(montants_trouves), reverse=True)
    if montants_trouves:
        budget_annuel = montants_trouves[0]
        if len(montants_trouves) > 1:
            candidat = montants_trouves[1]
            # Vérifier cohérence annuel/mensuel (tolérance 10 %)
            if budget_annuel > 0 and abs(candidat * 12 - budget_annuel) / budget_annuel < 0.10:
                loyer_mensuel = candidat

    # Fallback loyer mensuel explicite
    if not loyer_mensuel:
        raw = premier_match([
            r'loyer\s+mensuel\s+(?:de\s+)?([\d\s.,]+)\s*(?:DT|TND|dinars?)',
            r'(?:loyer|montant)\s+(?:mensuel|par\s+mois)\s*[:\-]?\s*([\d\s.,]+)',
            r'(?:قسط|الشهر(?:ي|ية))\s*[:\-]?\s*([\d\s.,]+)',
            r'معلوم\s+(?:الكراء\s+)?الشهري\s*[:\-]?\s*([\d\s.,]+)',
        ], t)
        if raw:
            loyer_mensuel = extraire_montant(raw)

    # Recalculs croisés
    if budget_annuel and not loyer_mensuel:
        loyer_mensuel = round(budget_annuel / 12, 2)
    elif loyer_mensuel and not budget_annuel:
        budget_annuel = round(loyer_mensuel * 12, 2)

    # ── Devise ────────────────────────────────────────────────────────────────
    devise = "TND"
    for d in ["EUR", "USD", "GBP", "MAD"]:
        if re.search(r'\b' + d + r'\b', t):
            devise = d
            break
    # S'assurer que "dinars" sans autre devise → TND
    if devise == "TND" and not re.search(r'\b(?:dinars?|DT|TND)\b', t, re.IGNORECASE):
        devise = "TND"  # valeur par défaut maintenue

    # ── Préavis ───────────────────────────────────────────────────────────────
    preavis = None
    # "préavis de trois mois" / "congé donné … six mois"
    m = re.search(
        r'(?:cong[eé]\s+donn[eé]|pr[eé]avis\s+de|d[eé]lai\s+de\s+pr[eé]avis|إشعار)\s+'
        r'(?:de\s+)?(\w+)\s+mois',
        t, re.IGNORECASE
    )
    if m:
        val = m.group(1).lower()
        preavis = CHIFFRES_FR.get(val) or (int(val) if val.isdigit() else None)
    # "30 jours" → converti en jours (pas mois)
    if not preavis:
        m_j = re.search(r'pr[eé]avis\s+(?:de\s+)?(\d+)\s*jours?', t, re.IGNORECASE)
        if m_j:
            preavis = int(m_j.group(1))
        elif re.search(r'30\s+(?:jours|يوم)', t, re.IGNORECASE):
            preavis = 30
        else:
            m_ar_pr = re.search(r'(?:مسبقة|إشعار|سابقة?)\s+ب\s+(\d+)\s+(?:يوم|أيام)', t)
            if m_ar_pr:
                preavis = int(m_ar_pr.group(1))

    # ── Renouvellement auto ───────────────────────────────────────────────────
    ren_auto = bool(re.search(
        r'renouvell|tacitement|reconduit|تجديد\s+ضمني|par\s+tacite\s+reconduction',
        t, re.IGNORECASE
    ))

    # ── Statut ────────────────────────────────────────────────────────────────
    statut = "Actif"
    if re.search(r'r[eé]sili[eé]|r[eé]siliation|فسخ', t, re.IGNORECASE):
        statut = "Résilié"
    elif date_fin:
        try:
            if datetime.strptime(date_fin, "%Y-%m-%d") < datetime.now():
                statut = "Expiré"
        except ValueError:
            pass

    # ── Téléphone bailleur ────────────────────────────────────────────────────
    tel_bailleur = None
    m = re.search(
        r'(?:t[eé]l[eé]phone|t[eé]l\.?|gsm|portable|الهاتف|فاكس?)\s*[:\.\-]?\s*'
        r'((?:\+?[\d\s\(\)\-\.]{7,20}))',
        t, re.IGNORECASE
    )
    if m:
        tel_bailleur = re.sub(r'[\s\-\.]', '', m.group(1))

    # ── Notes automatiques ────────────────────────────────────────────────────
    notes_parts = []
    m_idx = re.search(r'major[eé].{0,40}?(\d+)\s*%', t, re.IGNORECASE)
    if m_idx:
        notes_parts.append(f"Indexation loyer +{m_idx.group(1)}%/an")
    if re.search(r'\bTVA\b|taxe\s+(?:sur\s+la\s+valeur\s+ajout[eé]e|municipale)|القيمة\s+المضافة', t, re.IGNORECASE):
        notes_parts.append("TVA / Valeur Ajoutée à vérifier")
    if re.search(r'[eé]lectricit[eé]|consommation\s+d.eau|الكهرباء|الماء', t, re.IGNORECASE):
        notes_parts.append("Charges (eau, élec) à charge locataire")
    if re.search(r'enregistrement|timbre\s+fiscal|تسجيل', t, re.IGNORECASE):
        notes_parts.append("Frais d'enregistrement mentionnés")
    if re.search(r'[\u0600-\u06FF]', t):
        notes_parts.append("Contrat en arabe — vérifier les montants manuellement")

    _truly_extracted = [k for k, v in {
        "adresse": adresse, "bailleur": bailleur,
        "bailleur_confiance": bailleur_confiance, "bailleur_contexte": bailleur_contexte,
        "entite": entite,
        "type_bien": type_bien, "superficie": superficie,
        "num_contrat": num_contrat, "date_debut": date_debut, "date_fin": date_fin,
        "loyer_mensuel": loyer_mensuel, "budget_annuel": budget_annuel,
        "preavis_resiliation": preavis, "tel_bailleur": tel_bailleur,
        "notes": " | ".join(notes_parts) if notes_parts else None,
        "renouvellement_auto": ren_auto if ren_auto else None,
        "statut": statut if statut != "Actif" else None,
        "devise": devise if devise != "TND" else None,
    }.items() if v is not None]

    return {
        "adresse":             adresse,
        "reference":           None,
        "bailleur":            bailleur,
        "bailleur_confiance":  bailleur_confiance,
        "bailleur_contexte":   bailleur_contexte,
        "entite":              entite,
        "type_bien":           type_bien,
        "superficie":          superficie,
        "statut":              statut,
        "num_contrat":         num_contrat,
        "date_debut":          date_debut,
        "date_fin":            date_fin,
        "loyer_mensuel":       loyer_mensuel,
        "budget_annuel":       budget_annuel,
        "devise":              devise,
        "preavis_resiliation": preavis,
        "renouvellement_auto": ren_auto,
        "resp_interne":        None,
        "contact_bailleur":    bailleur,
        "tel_bailleur":        tel_bailleur,
        "notes":               " | ".join(notes_parts) if notes_parts else None,
        "_extracted_fields":   _truly_extracted,
    }


# ══════════════════════════════════════════════════════════════════════════════
# Point d'entrée
# ══════════════════════════════════════════════════════════════════════════════
if __name__ == "__main__":
    if len(sys.argv) < 2:
        sys.exit(json.dumps(
            {"error": "Usage : python extract_contrat.py <fichier> [zone] [max_pages]"},
            ensure_ascii=False
        ))

    chemin = sys.argv[1]
    zone   = sys.argv[2] if len(sys.argv) > 2 else "Tunisie"

    max_pages = MAX_PDF_PAGES
    if len(sys.argv) > 3:
        try:
            max_pages = max(1, int(sys.argv[3]))
        except (ValueError, TypeError):
            pass

    if not os.path.isfile(chemin):
        sys.exit(json.dumps(
            {"error": f"Fichier introuvable : {chemin}"},
            ensure_ascii=False
        ))

    try:
        texte  = ocr_fichier(chemin, max_pages)
        texte  = normaliser(texte)
        champs = extraire_champs(texte, zone)
        # ensure_ascii=False → accents et arabes restent lisibles dans la sortie
        print(json.dumps(champs, ensure_ascii=False))
    except Exception as e:
        sys.exit(json.dumps({"error": str(e)}, ensure_ascii=False))