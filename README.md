# Quality Assurance System

A high-fidelity, web-based Quality Assurance Portal designed for institutional excellence. This system manages accreditation tracking, document mapping, and activity evaluation with a focus on data integrity and professional aesthetics.

## 💻 Tech Stack
- **Frontend**: HTML5, Vanilla CSS3 (Custom Design System: Dark Blue `#001C57` & Gold `#DFB641`), Vanilla JavaScript (ES6+).
- **Backend**: PHP 8.1+ (Native, PDO-driven).
- **Database**: MySQL/MariaDB.

## 🔌 External APIs
The system integrates several external services to enhance functionality and data accuracy:

- **Philippine Standard Geographic Code (PSGC) API**:
  - **Base URL**: `https://psgc.gitlab.io/api/`
  - **Purpose**: Provides real-time, standardized data for Philippine Provinces, Cities, and Barangays. This ensures that user demographic data aligns with official national standards.
  - **Usage**: Implemented via asynchronous JavaScript fetch calls in the onboarding profile gate.

## 🚀 Key Modules & Features

### 1. Dynamic News Slideshow
- **Auto-Advance Logic**: 10-second smart timer with manual navigation override.
- **Responsive Hybrid Design**: Horizontal layout for desktop, vertical stack for mobile.
- **Local Asset Management**: High-speed image loading from integrated `assets/img/news/` storage.

### 2. Advanced Accreditation Tracker
- **Hierarchical Progress Bars**: Real-time completion tracking for categories and sub-categories.
- **Aggregate Completion Logic**: Overall accreditation progress calculated by summing all approved requirements across the entire hierarchy.
- **Interactive Checklist**: Premium custom checkmarks and "Approved" state visual cues (strikethrough & fade).
- **Dynamic Action Menus**: Context-aware dropdowns for managing requirements and categories.

### 3. Institutional "About" Grid
- **4-Pillar Layout**: Structured 2x2 grid for Mission, Vision, Objectives, and Services.
- **Custom Iconography**: Hand-crafted SVG icons with interactive hover states and reveal-on-scroll animations.

### 4. Robust Authentication & Onboarding
- **Mandatory Profile Completion**: Cascading PSGC address selector forces data accuracy before dashboard access.
- **Secure Session Management**: Server-side session validation and password hashing.

## 🔄 System Flow
1. **Landing & Discovery**: Users interact with the dynamic news feed and institutional pillars.
2. **Authentication**: Secure login gates access to the internal portal.
3. **Onboarding Gate**: System validates profile completeness; missing data triggers a mandatory cascading address selector.
4. **Module Interaction**: Centralized routing through `feed.php` handles module switching (`accreditation`, `activity`, `document`).

---
*Developed for Northern Bukidnon State College (NBSC) Quality Assurance Office.*
