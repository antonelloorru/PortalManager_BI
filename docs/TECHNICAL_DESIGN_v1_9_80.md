# Technical Design — v1.9.80 — Report Certificazioni

## Sorgenti (ER)
```
user_certifications uc ─► certifications cert ─► brands b (partnership_level)
                      │                        └► technologies t
                      └► employees e ─► companies (company_id), company_locations (location_id),
                                       departments (department_id | department testo), job_title, status
                                       └► cm_tech_profiles (is_active) ─► cm_tech_units (unità tecnica)
```
## Logiche
- Stato effettivo: `CASE expiry NULL → active · < oggi → expired · ≤ oggi + notify_days_1 → expiring · altro → active`.
- Reparto: `departments.name` se collegato, altrimenti il testo `employees.department`, altrimenti «(n.d.)».
- Unità tecnica: `EXISTS` su `cm_tech_profiles` attivi (un collaboratore può averne più d'una).
- Credly: `certificate_code` UUID oppure `employees.credly_url`.
- Tutti i filtri: `PmFilter::in/range`, AND fra filtri, OR fra valori dello stesso filtro.
