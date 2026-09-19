import json
import torch
import torch.nn as nn
from transformers import AutoTokenizer, AutoModel, AutoConfig
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import uvicorn
import pandas as pd
import xgboost as xgb

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

# ----- نقاط النهاية -----

@app.get("/")
def health_check():
    return {"status": "الخدمة شغالة", "nlp_model": "MARBERTv2", "decision_model": "XGBoost-87k"}

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