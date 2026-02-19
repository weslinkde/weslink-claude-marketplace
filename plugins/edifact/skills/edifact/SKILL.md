---
name: edifact
description: "EDIFACT INVOIC parsing for German telecom providers Telekom, Vodafone and O2/Telefónica. Covers provider detection via UNB segment, SIM/ICCID and phone number extraction, MOA/DTM/RFF qualifier tables, and billing period identification."
---

# EDIFACT INVOIC – German Telecom Providers

For detailed segment questions, load the relevant spec file from `references/` using the Read tool.

## Provider Detection via UNB S002.0004

| Provider      | Spec file                 | UNB S002.0004       | Syntax |
|---------------|---------------------------|---------------------|--------|
| Telekom       | `references/telekom.json` | starts with `D--01` | UNOA:4 |
| O2/Telefónica | `references/o2.json`      | `o2 MOBILFUNK`      | UNOC:4 |
| Vodafone      | `references/vodafone.json`| `MDVEDI`            | UNOC:1 |

**Always detect provider from UNB S002.0004 – never from the filename!**

## Contract Identifier Segments

### Telekom

| ID type       | Segment & qualifier                               | Notes |
|---------------|---------------------------------------------------|-------|
| SIM / ICCID   | `IMD+F++06:006:193:<ICCID>:<ProfileText>'`        | 19 digits, starts with `89`. MultiSIM: multiple ICCIDs separated by `/` |
| Phone number  | `IMD+F++F88:005:193:<Nr>:<Nr-Text>'` (ELMO)       | Format `(0171)1234567` – normalize to E.164 |
| Phone number  | `IMD+F++B01:005:193:<Nr>:<Nr-formatted>'` (ELFE)  | Landline, no special characters |
| Account no.   | `IMD+F++88:006:193:<FKTO>'`                       | Fernmeldekonto number |
| Account no.   | `RFF+ADE:<FKTO>'`                                 | Alternative in header (SG1) |
| Contract no.  | `RFF+CT:<Nr>'`                                    | Detail section (SG30) |

### O2/Telefónica

| ID type       | Segment & qualifier              | Notes |
|---------------|----------------------------------|-------|
| SIM / ICCID   | **not available**                | O2 does not transmit ICCID |
| Phone number  | `IMD+VI4+...'` (invoice)         | `0179123456` – normalize to E.164 |
| Phone number  | `IMD+MON+...'` (call detail)     | Same normalization |
| Account no.   | `RFF+IT:<Nr>'`                   | In header |
| Contract no.  | `RFF+CT:<Nr>'`                   | Framework contract number |

### Vodafone

| ID type            | Segment & qualifier              | Notes |
|--------------------|----------------------------------|-------|
| Phone number       | `RFF+CN:<NDC>/<MSISDN>'`         | e.g. `0172/8900545` – normalize to E.164. M2M: `00000000` |
| Customer no.       | `RFF+IT:<Nr>'`                   | 9 digits with leading zeros |
| Framework contract | `RFF+ADF:<Nr>'`                  | For business customers |
| Billing period     | `DTM+51:<Start>:102'` / `DTM+52:<End>:102'` | CCYYMMDD |

## Key Segment Qualifiers

### MOA – Monetary Amount
`MOA+<qualifier>:<amount>'`

| Qualifier | Meaning |
|-----------|---------|
| `9`   | Total gross amount |
| `79`  | Total net amount |
| `77`  | Vodafone gross total (incl. mobile payments) |
| `125` | Line item amount (taxable) |
| `203` | Line item gross amount |
| `66`  | Base price / minimum amount |
| `38`  | Informational amount (not included in totals) |
| `132` | Third-party provider amounts |
| `340` | Vodafone net total (excl. mobile payments) |

### DTM – Date/Time/Period
`DTM+<qualifier>:<date>:<format>'` (102=YYYYMMDD, 106=YYYYMM)

| Qualifier | Meaning |
|-----------|---------|
| `3`   | Invoice date |
| `167` | Billing period start – Telekom ELMO |
| `168` | Billing period end – Telekom ELMO |
| `157` | Billing period start – Telekom ELFE |
| `158` | Billing period end – Telekom ELFE |
| `51`  | Billing period start – Vodafone |
| `52`  | Billing period end – Vodafone |

### RFF – Reference
`RFF+<qualifier>:<value>'`

| Qualifier | Meaning | Provider |
|-----------|---------|---------|
| `CT`  | Contract / framework contract number | All |
| `ADE` | Booking account (Fernmeldekonto) | Telekom |
| `IT`  | Customer number | O2, Vodafone |
| `CN`  | MSISDN / phone number | Vodafone |
| `ADF` | Framework contract number | Vodafone |
| `VA`  | VAT ID | Telekom, O2 |

## Spec References

For detailed segment definitions, qualifier lists or examples, load the JSON file from `references/`:
- `references/telekom.json` – 20 segments, all Telekom formats (ELFE/ELMO/EVA/TDN)
- `references/vodafone.json` – 21 segments incl. M2M/IoT (ELMI11/ELMI12)
- `references/o2.json` – invoice + call detail records, 16 documented deviations from Telekom
