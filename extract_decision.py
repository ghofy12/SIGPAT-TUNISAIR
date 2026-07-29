
import sys
import io
# Forcer UTF-8 sur stdout/stderr — critique sur Windows
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
    sys.exit(json.dumps(
        {"error": "pytesseract/Pillow manquant : pip install pytesseract pillow"},
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
    return 'tesseract'

pytesseract.pytesseract.tesseract_cmd = _trouver_tesseract()

# ── pdf2image (Poppler) ────────────────────────────────────────────────────────
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

# ── Tables mois ───────────────────────────────────────────────────────────────
MOIS_AR = {
    'جانفي': '01', 'فيفري': '02', 'مارس': '03', 'أفريل': '04',
    'ماي':   '05', 'جوان':  '06', 'جويلية': '07', 'أوت':  '08',
    'سبتمبر':'09', 'أكتوبر':'10', 'نوفمبر': '11', 'ديسمبر':'12',
}
MOIS_FR = {
    'janvier':'01', 'février':'02', 'fevrier':'02', 'mars':'03',
    'avril':  '04', 'mai':    '05', 'juin':   '06', 'juillet':'07',
    'août':   '08', 'aout':   '08', 'septembre':'09',
    'octobre':'10', 'novembre':'11', 'décembre':'12', 'decembre':'12',
}
MOIS_ALL = {**MOIS_AR, **MOIS_FR}
MOIS_PATTERN = '|'.join(re.escape(m) for m in MOIS_ALL)

# ── Marques connues ───────────────────────────────────────────────────────────
MARQUES = [
    ('WVPASSAT|PASSAT',    'WV',          'PASSAT'),
    ('VOLKSWAGEN',         'VOLKSWAGEN',  None),
    ('PEUGEOT',            'PEUGEOT',     None),
    ('RENAULT',            'RENAULT',     None),
    ('CITRO[EË]N',         'CITROEN',     None),
    ('MERCEDES',           'MERCEDES',    None),
    ('BMW',                'BMW',         None),
    ('TOYOTA',             'TOYOTA',      None),
    ('HYUNDAI',            'HYUNDAI',     None),
    ('KIA',                'KIA',         None),
    ('FORD',               'FORD',        None),
    ('NISSAN',             'NISSAN',      None),
    ('SEAT',               'SEAT',        None),
    ('SKODA|ŠKODA',        'SKODA',       None),
    ('FIAT',               'FIAT',        None),
    ('OPEL',               'OPEL',        None),
]

# ══════════════════════════════════════════════════════════════════════════════
# OCR
# ══════════════════════════════════════════════════════════════════════════════
def _choisir_lang() -> str:
    try:
        langs = pytesseract.get_languages()
        parts = []
        if 'ara' in langs: parts.append('ara')
        if 'fra' in langs: parts.append('fra')
        if 'eng' in langs: parts.append('eng')
        return '+'.join(parts) if parts else 'eng'
    except Exception:
        return 'ara+fra+eng'


def ocr_fichier(chemin: str) -> str:
    lang = _choisir_lang()
    ext  = os.path.splitext(chemin)[1].lower()
    cfg  = "--psm 1 --oem 1"

    if ext == '.pdf':
        if not PDF_SUPPORT:
            sys.exit(json.dumps(
                {"error": "pdf2image manquant : pip install pdf2image"},
                ensure_ascii=False))
        kwargs = {"dpi": 300, "first_page": 1, "last_page": 2}
        if POPPLER_PATH:
            kwargs["poppler_path"] = POPPLER_PATH
        pages = convert_from_path(chemin, **kwargs)
        return "\n--- PAGE ---\n".join(
            pytesseract.image_to_string(p, lang=lang, config=cfg)
            for p in pages
        )

    return pytesseract.image_to_string(Image.open(chemin), lang=lang, config=cfg)


def normaliser(texte: str) -> str:
    for src, dst in [("—","-"),("–","-"),("\xa0"," "),("\u2019","'"),("\u200b",""),("\ufeff","")]:
        texte = texte.replace(src, dst)
    texte = texte.replace("\r\n", "\n").replace("\r", "\n")
    texte = re.sub(r"-\n(\w)", r"\1", texte)
    texte = re.sub(r'\n{3,}', '\n\n', texte)
    return texte


# ══════════════════════════════════════════════════════════════════════════════
# Extraction
# ══════════════════════════════════════════════════════════════════════════════
def normaliser_date(jour: str, mois_texte: str, annee: str) -> str | None:
    mo = MOIS_ALL.get(mois_texte.strip())
    if not mo:
        mo = MOIS_ALL.get(mois_texte.strip().lower())
    if not mo:
        return None
    try:
        return f"{annee}-{mo}-{jour.zfill(2)}"
    except Exception:
        return None


def extraire_champs(texte: str) -> dict:
    t = texte

    # ── Numéro immatriculation ────────────────────────────────────────────────
    # ex: "15-349548" ou "15 349548" ou "RS-349548"
    num_pe = None
    m = re.search(r'\b(\d{2,4}[\s\-]\d{4,6})\b', t)
    if m:
        num_pe = re.sub(r'\s+', '-', m.group(1).strip())

    # ── Marque / Genre ────────────────────────────────────────────────────────
    marque = None
    genre  = None
    for pattern, m_val, g_val in MARQUES:
        if re.search(r'\b(' + pattern + r')\b', t, re.IGNORECASE):
            marque = m_val
            genre  = g_val
            break

    # ── Affectation (nom de la personne) ─────────────────────────────────────
    affectation = None
    # Arabe: après "للسيد" → nom latin qui suit
    m = re.search(
        r'(?:للسيد|للسيدة)\s+([A-ZÉÀÂÇÈÊËÎÏÔÙÛÜ][a-zA-ZÀ-ÿ\s\-]{2,50}?)(?:\s*[-–,\n]|\s+(?:Matricule|معر|مكلف|M\.A))',
        t, re.UNICODE
    )
    if m:
        affectation = m.group(1).strip()

    # Fallback: "pour M." / "pour Mme"
    if not affectation:
        m = re.search(
            r'pour\s+M(?:r|me)?\.?\s+([A-ZÉÀÂÇÈÊËÎÏÔÙÛÜ][a-zA-ZÀ-ÿ\s\-]{2,50}?)(?:\s*[-–,\n])',
            t, re.IGNORECASE | re.UNICODE
        )
        if m:
            affectation = m.group(1).strip()

    # ── Matricule agent ───────────────────────────────────────────────────────
    matricule_agent = None
    m = re.search(r'(?:عدد|matricule|matr\.?)\s*[:\-]?\s*(\d{4,6})', t, re.IGNORECASE)
    if m:
        matricule_agent = m.group(1).strip()

    # ── Nature (V. Fonction / V. Service) ────────────────────────────────────
    nature = None
    if re.search(r'شخصي|personnell?|usage\s+personnel|fonction', t, re.IGNORECASE):
        nature = 'V. Fonction'
    elif re.search(r'service|مصلحة', t, re.IGNORECASE):
        nature = 'V. Service'

    # ── Carburant (litres/mois) ───────────────────────────────────────────────
    carburant_litres = None
    m = re.search(r'(\d+)\s*(?:لترا|litres?|L\/mois|litre)', t, re.IGNORECASE)
    if m:
        carburant_litres = int(m.group(1))

    # ── Date début ────────────────────────────────────────────────────────────
    date_debut = None
    m = re.search(
        r'(?:من\s+يوم|à\s+compter\s+du|اعتبارا\s+من)\s+(\d{1,2})\s+(' + MOIS_PATTERN + r')\s+(\d{4})',
        t, re.IGNORECASE | re.UNICODE
    )
    if m:
        date_debut = normaliser_date(m.group(1), m.group(2), m.group(3))

    # ── Date fin ──────────────────────────────────────────────────────────────
    date_fin = None
    m = re.search(
        r'(?:إلى\s+غاية|jusqu\'?au|jusqu\'?à)\s+(\d{1,2})\s+(' + MOIS_PATTERN + r')\s+(\d{4})',
        t, re.IGNORECASE | re.UNICODE
    )
    if m:
        date_fin = normaliser_date(m.group(1), m.group(2), m.group(3))

    return {
        "num_pe":           num_pe,
        "marque":           marque,
        "genre":            genre,
        "affectation":      affectation,
        "matricule_agent":  matricule_agent,
        "nature":           nature,
        "carburant_litres": carburant_litres,
        "date_debut":       date_debut,
        "date_fin":         date_fin,
    }


# ══════════════════════════════════════════════════════════════════════════════
# Point d'entrée
# ══════════════════════════════════════════════════════════════════════════════
if __name__ == "__main__":
    if len(sys.argv) < 2:
        sys.exit(json.dumps(
            {"error": "Usage : python extract_decision.py <fichier.pdf|image>"},
            ensure_ascii=False))

    chemin = sys.argv[1]
    if not os.path.isfile(chemin):
        sys.exit(json.dumps(
            {"error": f"Fichier introuvable : {chemin}"},
            ensure_ascii=False))

    try:
        texte  = ocr_fichier(chemin)
        texte  = normaliser(texte)
        champs = extraire_champs(texte)
        print(json.dumps(champs, ensure_ascii=False))
    except Exception as e:
        sys.exit(json.dumps({"error": str(e)}, ensure_ascii=False))