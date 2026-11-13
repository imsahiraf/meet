# Meet v1.0 - Laravel Architecture Document (LAD)

## Document Information

**Project Name:** Meet

**Version:** 1.0

**Framework:** Laravel 12

**PHP:** 8.3+

**Architecture Style:** Modular Monolith

**Deployment Target:** cPanel (Version 1)

---

# 1. Architecture Philosophy

Meet shall be implemented as a **Modular Monolith**.

Each feature shall exist as an independent Laravel module while sharing a common Core layer.

Modules shall communicate through:

* Services
* Interfaces (Contracts)
* Events
* Queued Jobs
* Well-defined APIs

Direct cross-module database manipulation is discouraged.

---

# 2. Root Folder Structure

```text
app/
bootstrap/
config/
database/
docs/
lang/
public/
resources/
routes/
storage/
tests/
vendor/

Modules/
```

---

# 3. Modules Directory

```text
Modules/

Core/
Authentication/
User/
Contacts/
Chat/
Groups/
Media/
VoiceCall/
VideoCall/
Meeting/
Whiteboard/
Calendar/
Notification/
Guest/
Archive/
Analytics/
Security/
Admin/
Configuration/
Shared/
```

Every module shall remain independent.

---

# 4. Standard Module Structure

```text
ModuleName/

Config/

Console/

Contracts/

DTO/

Events/

Exceptions/

Helpers/

Http/

    Controllers/

    Middleware/

    Requests/

    Resources/

Jobs/

Listeners/

Mail/

Models/

Notifications/

Observers/

Policies/

Providers/

Repositories/

Routes/

Rules/

Services/

Traits/

Views/ (only if required)

Tests/
```

Every module shall follow the same structure.

---

# 5. Controller Responsibilities

Controllers shall:

* Receive requests
* Validate authorization
* Delegate business logic to Services
* Return API Resources

Controllers shall **not**:

* Contain business logic
* Execute complex database queries
* Send emails directly
* Generate reports

---

# 6. Service Layer

Each module shall contain one or more Services responsible for business logic.

Example:

```text
AuthenticationService
MeetingService
ChatService
VideoCallService
WhiteboardService
ArchiveService
```

Services shall be reusable and testable.

---

# 7. Repository Layer

Repositories shall encapsulate all database access.

Example:

```text
UserRepository
ChatRepository
MeetingRepository
ArchiveRepository
```

Repositories shall use:

* Eloquent ORM
* Query Builder
* Parameterized queries

Raw SQL should be limited to justified performance cases.

---

# 8. DTO (Data Transfer Objects)

DTOs shall carry validated data between layers.

Examples:

```text
CreateMeetingDTO
SendMessageDTO
RegisterUserDTO
UploadFileDTO
```

DTOs shall remain immutable where practical.

---

# 9. Form Requests

Validation shall use Laravel Form Requests.

Examples:

```text
RegisterRequest
LoginRequest
CreateMeetingRequest
SendMessageRequest
```

Validation logic shall not be duplicated.

---

# 10. API Resources

API Resources shall control JSON output.

Examples:

```text
UserResource
MeetingResource
ChatMessageResource
NotificationResource
```

Responses shall follow the API contract defined in the SRS.

---

# 11. Events & Listeners

The application shall use Laravel Events for decoupled processing.

Example Events:

* UserRegistered
* MessageSent
* MeetingCreated
* FileUploaded
* CallStarted

Example Listeners:

* SendWelcomeEmail
* GenerateICS
* NotifyParticipants
* UpdateAnalytics
* LogActivity

---

# 12. Queue Jobs

Heavy tasks shall execute asynchronously.

Examples:

* SendEmailJob
* SendMeetingInvitationJob
* GenerateThumbnailJob
* ArchiveDataJob
* CleanupTemporaryFilesJob

Queue drivers shall be configurable.

---

# 13. Middleware

Global Middleware

* HTTPS Enforcement
* Maintenance Mode
* Request Logging

Route Middleware

* Authentication
* Role Validation
* Permission Validation
* Rate Limiting
* Guest Access Validation

---

# 14. Policies

Authorization shall use Laravel Policies.

Examples:

```text
MeetingPolicy
MessagePolicy
FilePolicy
AdminPolicy
```

Permission checks shall not be duplicated in controllers.

---

# 15. Configuration Management

Every configurable value shall be stored in configuration or managed through the Configuration Engine.

Examples:

* Polling intervals
* Upload limits
* Meeting defaults
* Guest duration
* STUN/TURN servers
* SMTP
* Branding

Hardcoded operational values are prohibited.

---

# 16. Coding Standards

## Naming

Classes:

```text
MeetingService
```

Methods:

```text
createMeeting()
```

Variables:

```text
meetingParticipants
```

Database Tables:

```text
meeting_participants
```

Routes:

```text
POST /api/v1/meetings
```

---

# 17. Dependency Rules

Allowed:

Controller → Service

Service → Repository

Repository → Database

Service → Event

Event → Listener

Listener → Queue Job

Not Allowed:

Controller → Repository

Controller → Database

Controller → Mail

View → Database

Module → Another Module's Internal Classes

Cross-module interaction shall occur through Contracts or Services.

---

# 18. Error Handling

Modules shall define custom exceptions where appropriate.

Examples:

* MeetingNotFoundException
* GuestAccessDeniedException
* OneViewExpiredException

Global exception handling shall return standardized API responses.

---

# 19. Testing Strategy

Every module shall include:

* Unit Tests
* Feature Tests
* Integration Tests (where applicable)

Critical workflows shall have end-to-end test scenarios documented.

---

# 20. Documentation Requirements

Every module shall include:

* README
* Architecture Notes
* API Documentation
* Database Changes
* Changelog
* Test Coverage Summary

---

# 21. White-Label Support

Modules shall not hardcode:

* Application Name
* Company Name
* Email Branding
* Logos
* Colors
* URLs

Branding shall be retrieved from the Configuration Engine.

---

# 22. Future Readiness

The architecture shall support future integration of:

* Native Mobile Applications
* AI Services
* Cloud Storage
* Push Notifications
* Enterprise Authentication
* Plugin Modules

without requiring major structural changes.

---

# 23. Development Principles

Always:

* Use Dependency Injection
* Keep Services focused
* Queue expensive operations
* Validate inputs
* Log significant events
* Respect module boundaries
* Write tests
* Update documentation

Never:

* Duplicate business logic
* Hardcode configuration
* Bypass authorization
* Access databases directly from controllers
* Store secrets in source code
* Mix presentation with business logic

---

# Document Summary

This Laravel Architecture Document defines the internal structure of the Meet codebase. It establishes the modular architecture, folder organization, service and repository layers, DTO usage, event-driven processing, queue integration, authorization model, coding standards, dependency rules, testing expectations, and white-label principles. All implementation work for Meet shall follow these architectural guidelines to ensure consistency, maintainability, and long-term scalability.
