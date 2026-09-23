import json
import torch
import torch.nn as nn
from transformers import AutoTokenizer, AutoModel, AutoConfig
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import uvicorn
import pandas as pd
import xgboost as xgb
import lightgbm as lgb

MODEL_NAME = "UBC-NLP/MARBERTv2"
MAX_LEN = 32

from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent

# =====================================================
#  NLP: تحميل نموذج MARBERTv2
# =====================================================

with open(BASE_DIR / "label_maps.json", encoding="utf-8") as f:
    maps = json.load(f)

LABELS     = [k for k, v in sorted(maps["label2id"].items(), key=lambda x: x[1])]
SEVERITIES = [int(k) for k, v in sorted(maps["sev2id"].items(), key=lambda x: x[1])]
CATEGORIES = [k for k, v in sorted(maps["cat2id"].items(), key=lambda x: x[1])]

class MultiTaskDriverModel(nn.Module):
    def __init__(self, config, n_labels, n_sev, n_cat, dropout=0.3):
        super().__init__()
        self.backbone = AutoModel.from_config(config)
        hidden = self.backbone.config.hidden_size
        self.dropout = nn.Dropout(dropout)
        self.label_head = nn.Linear(hidden, n_labels)
        self.sev_head   = nn.Linear(hidden, n_sev)
        self.cat_head   = nn.Linear(hidden, n_cat)

    def forward(self, input_ids, attention_mask):
        out    = self.backbone(input_ids=input_ids, attention_mask=attention_mask)
        pooled = out.last_hidden_state[:, 0]
        pooled = self.dropout(pooled)
        return self.label_head(pooled), self.sev_head(pooled), self.cat_head(pooled)

import sys
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
if hasattr(sys.stderr, 'reconfigure'):
    sys.stderr.reconfigure(encoding='utf-8', errors='replace')

print("[AI Service] Loading NLP model...")
device    = torch.device("cpu")
tokenizer = AutoTokenizer.from_pretrained(str(BASE_DIR))
config    = AutoConfig.from_pretrained(MODEL_NAME)
nlp_model = MultiTaskDriverModel(config, len(LABELS), len(SEVERITIES), len(CATEGORIES)).to(device)
nlp_model.load_state_dict(torch.load(BASE_DIR / "driver_reviews_model.pt", map_location=device))
nlp_model.eval()
print("[AI Service] NLP model ready. Labels:", len(LABELS), "Categories:", len(CATEGORIES))

# =====================================================
#  XGBoost: تحميل نموذج القرار
# =====================================================

DECISION_MODEL_PATH = BASE_DIR / "models" / "xgboost_decision_engine_87k.json"

DECISION_FEATURE_NAMES = [
    "current_rating",
    "previous_warnings",
    "trips_count",
    "sentiment_pred",
    "sentiment_confidence",
    "category_pred",
    "category_confidence",
]

DECISION_LABELS = {
    0: "NO_ACTION",
    1: "REWARD",
    2: "MODERATE_VIOLATION",
    3: "FORMAL_WARNING",
    4: "ADMIN_REVIEW_REQUIRED",
}

print("[AI Service] Loading XGBoost decision model...")
decision_booster = xgb.Booster()
decision_booster.load_model(str(DECISION_MODEL_PATH))
print("[AI Service] XGBoost decision model ready:", DECISION_MODEL_PATH.name)

# =====================================================
#  LightGBM: تحميل نموذج ترتيب وتوصية السائقين
# =====================================================

RANKER_MODEL_PATH = BASE_DIR / "models" / "darbi_lgb_ranker_robust.txt"
if not RANKER_MODEL_PATH.exists():
    RANKER_MODEL_PATH = BASE_DIR.parent / "python" / "models" / "darbi_lgb_ranker_robust.txt"

print("[AI Service] Loading LightGBM ranker model...")
ranker_booster = lgb.Booster(model_file=str(RANKER_MODEL_PATH))
print("[AI Service] LightGBM ranker model ready:", RANKER_MODEL_PATH.name)

# =====================================================
#  FastAPI Application
# =====================================================

app = FastAPI(title="Darby AI Service", version="2.0")

# ----- مخطط البيانات -----

class CommentInput(BaseModel):
    text: str

class DecisionInput(BaseModel):
    current_rating:       float
    previous_warnings:    int
    trips_count:          int
    sentiment_pred:       int
    sentiment_confidence: float
    category_pred:        int
    category_confidence:  float

class DriverRankItem(BaseModel):
    driver_id:            int
    rating:               float = 1.0
    recent_rating:        float = 1.0
    trip_completion:      float = 1.0
    successful_stops:     float = 1.0
    punctuality:          float = 1.0
    confirmed_complaints: float = 0.0
    breakdown:            float = 0.0
    driver_absence:       float = 0.0

class DriverRankRequest(BaseModel):
    drivers: list[DriverRankItem]

# ----- نقاط النهاية -----

@app.get("/")
def health_check():
    return {
        "status": "الخدمة شغالة",
        "nlp_model": "MARBERTv2",
        "decision_model": "XGBoost-87k",
        "ranker_model": "LightGBM-LambdaRank",
    }

@app.post("/rank")
def rank_drivers(payload: DriverRankRequest):
    """ترتيب قائمة السائقين بناءً على نموذج LightGBM LambdaRank ومقاييس الأداء الثمانية"""
    try:
        if not payload.drivers:
            return {"status": "success", "total_ranked": 0, "drivers": []}

        records = [d.model_dump() for d in payload.drivers]
        df = pd.DataFrame(records)[ranker_booster.feature_name()]
        raw_scores = ranker_booster.predict(df)

        ranked_list = []
        for rec, score in zip(records, raw_scores):
            sc = float(score)
            reasons = []
            if rec["punctuality"] >= 0.95:
                reasons.append("التزام استثنائي بالمواعيد (>=95%)")
            elif rec["punctuality"] < 0.80:
                reasons.append("انخفاض في دقة المواعيد (<80%)")

            if rec["confirmed_complaints"] == 0:
                reasons.append("سجل نظيف خالٍ من الشكاوى")
            else:
                reasons.append(f"توجد شكاوى مسجلة ({round(rec['confirmed_complaints']*5)})")

            if rec["trip_completion"] >= 0.95:
                reasons.append("نسبة إنجاز رحلات عالية (>=95%)")

            if rec["driver_absence"] == 0:
                reasons.append("انضباط تام بدون غيابات")
            else:
                reasons.append(f"سجل غيابات مسجل ({round(rec['driver_absence']*5)})")

            ranked_list.append({
                "driver_id": rec["driver_id"],
                "ai_score": round(sc, 4),
                "reasons": reasons,
                "metrics": rec,
            })

        # فرز تنازلي حسب نقاط الذكاء الاصطناعي
        ranked_list.sort(key=lambda x: x["ai_score"], reverse=True)
        for idx, item in enumerate(ranked_list, 1):
            item["ai_rank"] = idx

        return {
            "status": "success",
            "model": "LightGBM-LambdaRank",
            "total_ranked": len(ranked_list),
            "drivers": ranked_list,
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Ranking error: {str(e)}")

@app.post("/classify")
def classify(payload: CommentInput):
    """تصنيف تعليق ولي الأمر: label / severity / category"""
    enc = tokenizer(
        payload.text, truncation=True, padding="max_length",
        max_length=MAX_LEN, return_tensors="pt",
    )
    with torch.no_grad():
        l_logits, s_logits, c_logits = nlp_model(enc["input_ids"], enc["attention_mask"])

    import torch.nn.functional as F
    label_probs    = F.softmax(l_logits, dim=1)[0].tolist()
    category_probs = F.softmax(c_logits, dim=1)[0].tolist()

    label_idx    = l_logits.argmax(1).item()
    category_idx = c_logits.argmax(1).item()

    return {
        "label":                  LABELS[label_idx],
        "severity":               SEVERITIES[s_logits.argmax(1).item()],
        "category":               CATEGORIES[category_idx],
        "sentiment_confidence":   round(label_probs[label_idx], 4),
        "category_confidence":    round(category_probs[category_idx], 4),
        "sentiment_pred":         label_idx,
        "category_pred":          category_idx,
    }

@app.post("/decision/predict")
def predict_decision(payload: DecisionInput):
    """قرار XGBoost للسائق بناءً على الميزات السبع"""
    try:
        df   = pd.DataFrame([payload.model_dump()])[DECISION_FEATURE_NAMES]
        dmat = xgb.DMatrix(df)
        raw  = decision_booster.predict(dmat)

        # predict() يُعيد مصفوفة احتمالات متعددة الأعمدة (softmax output)
        if raw.ndim == 2:
            probs = raw[0].tolist()
        else:
            # احتمالية ثنائية — حوّلها
            probs = [float(1 - raw[0]), float(raw[0])]

        decision_code = int(probs.index(max(probs)))
        confidence    = round(max(probs), 4)

        return {
            "decision_code":  decision_code,
            "decision_name":  DECISION_LABELS.get(decision_code, "UNKNOWN"),
            "confidence":     confidence,
            "probabilities":  {DECISION_LABELS.get(i, str(i)): round(p, 4) for i, p in enumerate(probs)},
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"XGBoost prediction error: {str(e)}")

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8001)