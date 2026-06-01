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

## Major Parts of this system
## 1. Activity Monitoring and Evaluation

## Pages:
1. Activity Evaluation
2. Evaluation Monitoring

## Page Contents:
1. Activity Evaluation
	This page contains the complete details and statistical data of the activity.

2. Evaluation Monitoring
	A sub-system to monitor the status and the actions taken to reach or to comply with the suggestions and/or complaints.

## Features:
1. Activity CRUD
2. Google Form CRUD
3. Has Analytics for the Quantitative Data gathered 
4. Uses Gmail API to go and search for the Email Request
5. Toggle AI Interpretation for Suggestions and Complaints

## In Development:
1. Activity Archival
2. Evaluation Monitoring Page

## 2. Accrediitation Tracking and File Archiving

## Pages:
1. Accreditation Masterlist
2. Accreditation Mapping
3. Accreditation Tracker

## Page Contents:
1. Accreditation Masterlist
  This page consist of the standard listing of all the registered accreditations.

2. Accreditation Mapping
  This page is more focused on mapping similar or the same documents for other accreditations to improve effeciency with document retrieval.

3. Accreditation Tracker
  This page is mainly for the File Archival and Progression Tracking. This is the only page accessible for non-Quality Assurance Office Staffs.

## Features:
1. Google Drive API for the File Upload.
2. Quality Assurance Office staff only File Approval.
3. Bulk Upload for accreditation registry. Template for SUC, AACCUP Institutional and Program, COPC
4. AI Interpretation for Bulk Registry
5. Action Logging
6. Accreditation, Categories and Requirments CRUD

## In Development:
1. Email SMTP for the service.
2. Proof Management for Document Bridging

## 3. Document Registry

## Pages:
1. Document Mapping
2. Accreditation Linkage

## Page Content:
1. Document Mapping
  Page to look for similar documents from the same office and other offices using similarity scoring.

2. Accreditation Linkage
  Main purpose of this page is to link proof to documents and look for different accreditation requirements using the same or closely similar documents.

## Features:
1. Similarity Scoring with Lightweight AI solutions
2. Document CRUD

## In Developemnt:
1. Systemic Flow(Data Deprived)

---
*Developed for Northern Bukidnon State College (NBSC) Quality Assurance Office.*
