# Quality Assurance System

A robust, web-based Quality Assurance Portal designed to manage institutional accreditation, document mapping, and activity evaluation.

## 💻 Tech Stack
- **Frontend**: HTML5, Vanilla CSS (Custom Theme: Dark Blue `#001C57` & Gold `#DFB641`), Vanilla JavaScript.
- **Backend**: Native PHP 8+.
- **Database**: MySQL (accessed securely via PDO).
- **External APIs**: 
  - Philippine Standard Geographic Code (PSGC) API for dynamic address selection.
  - Google OAuth API (UI Integration).

## 🔄 System Flow
1. **Authentication & Routing**
   - The application relies on a centralized router (`views/feed.php`).
   - Unauthenticated users are strictly routed to the Login or Sign-up pages.
   - Successful authentication establishes a secure server-side session and redirects the user to the Dashboard.
2. **Profile Validation Gate**
   - Upon loading the Dashboard, the system queries the database to check the user's demographic and geographic completion status.
   - If required fields (e.g., Position, Office, Province, City, Barangay) are missing, an unavoidable modal intercepts the user, requiring them to complete their profile before accessing the portal.
3. **Dashboard & Modules**
   - Once validated, users interact with the main Dashboard containing module shortcuts.
   - Clicking a module directs the router to load specific sub-systems:
     - `?action=accreditation` -> Accreditation Tracker
     - `?action=activity` -> Activity Monitoring & Evaluation
     - `?action=document` -> Document Mapping

## ✨ Added Features
- **Dynamic Address Selector**: Integrated the official PSGC API to provide a live, cascading dropdown for Philippine Provinces, Cities/Municipalities, and Barangays.
- **Mandatory Onboarding Modal**: A UI-blocking blur overlay that forces new users to complete their demographics before interacting with the system.
- **Secure Authentication**: Password hashing and verification using native PHP security functions.
- **Relational Schema Structure**: Mapped users precisely to nested institutional structures (`divisions` -> `offices` -> `sections` -> `programs`).
- **Dynamic Routing Engine**: Single-entry-point routing through `feed.php` to prevent direct access to isolated UI components.
