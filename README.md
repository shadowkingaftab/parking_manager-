# Parking System

A simple parking management app with a Flask backend and HTML frontend.

## Files

- `app.py` — Flask backend API server.
- `index.html` — Frontend page.
- `parking_system.db` — SQLite database (ignored in git).

## Run locally

1. Activate your Python environment if needed.
2. Install dependencies:
   ```bash
   pip install flask flask-cors bcrypt
   ```
3. Run the backend:
   ```bash
   python app.py
   ```
4. Open the frontend with a static server:
   ```bash
   python -m http.server 8000
   ```
   Then visit: `http://localhost:8000/`

## Login credentials

- Username: `admin`
- Password: `admin123`

## Notes

Do not commit `parking_system.db` or your local virtual environment to GitHub. Use this repo as a code project only.
