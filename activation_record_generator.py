import base64
import plistlib
import random
import string
import sys
import uuid

# --- Static Templates (from analysis) ---

ACCOUNT_TOKEN_CERT_TEMPLATE = """
-----BEGIN CERTIFICATE-----
MIIDZzCCAk+gAwIBAgIBAjANBgkqhkiG9w0BAQUFADBxMQswCQYDVQQGEwJVUzET
MBEGA1UEChMKQXBwbGUgSW5jLjEmMCQGA1UECxMdQXBwbGUgQ2VydGlmaWNhdGlv
biBBdXRob3JpdHkxLTArBgNVBAMTJEFwcGxlIGlQaG9uZSBDZXJ0aWZpY2F0aW9u
IEF1dGhvcml0eTAeFw0wNzA0MTYyMjU1MDJaFw0xNDA0MTYyMjU1MDJaMFsxCzAJ
BgNVBAYTAlVTMRMwEQYDVQQKEwpBcHBsZSBJbmMuMRUwEwYDVQQLEwxBcHBsZSBp
UGhvbmUxIDAeBgNVBAMTF0FwcGxlIGlQaG9uZSBBY3RpdmF0aW9uMIGfMA0GCSqG
SIb3DQEBAQUAA4GNADCBiQKBgQDFAXzRImArmohHfbS2oPcqAfbEv0d1jk7GbnX7
+4YUlyIfprzBVdlmz2JHYv1+04IzJtL7cL97UI7fk0i0OMY0al8a+JPQa4Ug611T
bqEt+njAmAkge3HXWDBdAXD9MhkC7T/9o77zOQ1oli4cUdzlnYWfzmW0PduOxuve
AeYY4wIDAQABo4GbMIGYMA4GA1UdDwEB/wQEAwIHgDAMBgNVHRMBAf8EAjAAMB0G
A1UdDgQWBBShoNL+t7Rz/psUaq/NPXNPH+/WlDAfBgNVHSMEGDAWgBTnNCouIt45
YGu0lM53g2EvMaB8NTA4BgNVHR8EMTAvMC2gK6AphidodHRwOi8vd3d3LmFwcGxl
LmNvbS9hcHBsZWNhL2lwaG9uZS5jcmwwDQYJKoZIhvcNAQEFBQADggEBAF9qmrUN
dA+FROYG7pWcYTAK+pLyOf9zOaE7aeVI885V8Y/BKHhlwAo+zEkiOU3FbEPCS9V
tS18ZBcwD/+d5ZQTMFknhcUJwdPqqjnm9LqTfH/x4pw8ONHRDzxHdp96gOV3A4+8
abkoASfcYqvIRypXnbur3bRRhTzAs4VILS6jTyFYymZeSewdBubmmigo1kCQiZGc
76c5feDAyHb2bzEqtvx3WprljdS46QT5CR6YelinZnio32jAzRYTxtS6r3JsvZDi
J07+EHcmfGdpxwgO+7btW1pFar0ZjF9/jYKKnOYNyvCrwszhafbSYwzAG5EJoXFB
4d+piWHUDcPxtcc=
-----END CERTIFICATE-----
"""

# For other certificates and blobs, we will generate random data.

def generate_random_blob(length=512):
    return base64.b64encode(random.choice(string.ascii_letters).encode() * length).decode()

def generate_activation_record(device_info):
    """
    Generates a complete activation record for the given device info.
    """

    # --- 1. Create the inner AccountToken plist-string ---
    activation_randomness = str(uuid.uuid4()).upper()

    # Use device_info, but provide defaults if keys are missing
    account_token_dict = {
        'InternationalMobileEquipmentIdentity': device_info.get('imei', ''),
        'ActivationTicket': generate_random_blob(256),
        'PhoneNumberNotificationURL': "https://albert.apple.com/deviceservices/phoneHome",
        'InternationalMobileSubscriberIdentity': ''.join(random.choices(string.digits, k=15)),
        'ProductType': device_info.get('product_type', 'iPhone14,5'),
        'UniqueDeviceID': device_info.get('udid', ''),
        'SerialNumber': device_info.get('serial_number', ''),
        'MobileEquipmentIdentifier': device_info.get('imei', '')[:-1],
        'InternationalMobileEquipmentIdentity2': ''.join(random.choices(string.digits, k=15)),
        'PostponementInfo': {},
        'ActivationRandomness': activation_randomness,
        'ActivityURL': "https://albert.apple.com/deviceservices/activity",
        'IntegratedCircuitCardIdentity': ''.join(random.choices(string.digits, k=20)),
    }

    # Format it in the weird string-plist way Apple does
    account_token_str = "{\n"
    for key, value in account_token_dict.items():
        if isinstance(value, str):
            account_token_str += f'\t"{key}" = "{value}";\n'
        elif isinstance(value, dict):
             account_token_str += f'\t"{key}" = {{}};\n'
    account_token_str += "}"

    # --- 2. Build the final ActivationRecord dictionary ---

    activation_record = {
        'ActivationRecord': {
            'unbrick': True,
            'AccountTokenCertificate': ACCOUNT_TOKEN_CERT_TEMPLATE.strip().encode(),
            'DeviceCertificate': generate_random_blob(1024).encode(),
            'RegulatoryInfo': b'eyJtYW51ZmFjdHVyaW5nRGF0ZSI6IjIwMjMtMDktMjBUMDM6MjI6MTZaIiwiZWxhYmVsIjp7ImJpcyI6eyJyZWd1bGF0b3J5IjoiUi00MTA5NDg5NyJ9fSwiY291bnRyeU9mT3JpZ2luIjp7Im1hZGVJbiI6IkNITiJ9fQ==',
            'FairPlayKeyData': generate_random_blob(2048).encode(),
            'AccountToken': account_token_str.encode(),
            'AccountTokenSignature': generate_random_blob(128).encode(),
            'UniqueDeviceCertificate': generate_random_blob(1024).encode(),
        }
    }

    # --- 3. Convert to XML plist string ---
    return plistlib.dumps(activation_record, fmt=plistlib.FMT_XML).decode()

if __name__ == '__main__':
    # This allows us to call this script from PHP
    if len(sys.argv) != 5:
        print("Usage: python activation_record_generator.py <udid> <serial> <imei> <product_type>")
        sys.exit(1)

    device_info = {
        'udid': sys.argv[1],
        'serial_number': sys.argv[2],
        'imei': sys.argv[3],
        'product_type': sys.argv[4],
    }

    record = generate_activation_record(device_info)
    print(record)
