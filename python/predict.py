import sys
import json
from pathlib import Path
import lightgbm as lgb
import pandas as pd

def main():
    try:
        input_data = sys.stdin.read()
        if not input_data:
            print(json.dumps({"error": "No input provided"}))
            return

        drivers = json.loads(input_data)
        if not drivers:
            print(json.dumps([]))
            return

        model_path = Path(__file__).resolve().parent / "models" / "darbi_lgb_ranker_robust.txt"
        booster = lgb.Booster(model_file=str(model_path))

        records = []
        for d in drivers:
            raw_rating = float(d.get("rating", d.get("rating_avg", 5.0)))
            rating = raw_rating / 5.0 if raw_rating > 1.0 else raw_rating
            records.append({
                "rating": rating,
                "recent_rating": float(d.get("recent_rating", rating)),
                "trip_completion": float(d.get("trip_completion", 1.0)),
                "successful_stops": float(d.get("successful_stops", 1.0)),
                "punctuality": float(d.get("punctuality", 1.0)),
                "confirmed_complaints": float(d.get("confirmed_complaints", 0.0)),
                "breakdown": float(d.get("breakdown", 0.0)),
                "driver_absence": float(d.get("driver_absence", 0.0)),
            })

        df = pd.DataFrame(records)[booster.feature_name()]
        scores = booster.predict(df)

        for d, s in zip(drivers, scores):
            d["ai_score"] = round(float(s), 4)

        ranked = sorted(drivers, key=lambda x: x["ai_score"], reverse=True)
        for idx, item in enumerate(ranked, 1):
            item["ai_rank"] = idx

        print(json.dumps(ranked))
    except Exception as e:
        print(json.dumps({"error": str(e)}))

if __name__ == "__main__":
    main()
