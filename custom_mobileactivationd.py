import socket
import plistlib
import struct
import threading

# --- Command Handlers (from previous step) ---

DEVICE_INFO = {
    'UniqueDeviceID': '00008101-001E452221E8001E',
    'SerialNumber': 'F9FZQ3J7P387',
    'ProductType': 'iPhone13,3',
    'InternationalMobileEquipmentIdentity': '350000000000000'
}

def handle_create_session_info(request_plist):
    print("[Daemon] Handling CreateTunnel1SessionInfoRequest")
    response_value = {
        'HandshakeRequestMessage': b'SimulatedHandshakeRequest'
    }
    return {'Value': response_value}

def handle_create_activation_info(request_plist):
    print("[Daemon] Handling CreateTunnel1ActivationInfoRequest")
    activation_info_dict = {
        'ActivationInfoXML': plistlib.dumps(DEVICE_INFO),
    }
    response_value = plistlib.dumps(activation_info_dict)
    return {'Value': response_value}

def handle_activation_response(request_plist):
    print("[Daemon] Handling HandleActivationInfoWithSessionRequest")
    print(f"[Daemon] Device {DEVICE_INFO['UniqueDeviceID']} successfully activated!")
    return {'Status': 'Success'}

COMMAND_HANDLERS = {
    'CreateTunnel1SessionInfoRequest': handle_create_session_info,
    'CreateTunnel1ActivationInfoRequest': handle_create_activation_info,
    'HandleActivationInfoWithSessionRequest': handle_activation_response,
}


# --- Daemon Server Logic ---

class MobileActivationDaemon:
    def __init__(self, host='localhost', port=5555):
        self.host = host
        self.port = port
        self.server_socket = None

    def start(self):
        self.server_socket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.server_socket.bind((self.host, self.port))
        self.server_socket.listen(1)
        print(f"[Daemon] Listening on {self.host}:{self.port} for mobileactivationd connections...")

        while True:
            conn, addr = self.server_socket.accept()
            print(f"[Daemon] Accepted connection from {addr}")
            # Use a thread to handle the client so the server can accept more connections
            thread = threading.Thread(target=self.handle_client, args=(conn,))
            thread.daemon = True
            thread.start()

    def handle_client(self, conn):
        try:
            while True:
                # Read the 4-byte length prefix
                raw_len = conn.recv(4)
                if not raw_len:
                    break

                msg_len = struct.unpack('!I', raw_len)[0]

                # Read the full plist message
                msg_body = conn.recv(msg_len)
                if not msg_body:
                    break

                request_plist = plistlib.loads(msg_body)
                print(f"[Daemon] Received command: {request_plist.get('Command')}")

                # Dispatch to the correct handler
                command = request_plist.get('Command')
                handler = COMMAND_HANDLERS.get(command)

                if handler:
                    response_plist = handler(request_plist)
                else:
                    response_plist = {'Error': f'Unknown command: {command}'}

                # Send the response
                response_data = plistlib.dumps(response_plist)
                response_len = struct.pack('!I', len(response_data))
                conn.sendall(response_len + response_data)

        except Exception as e:
            print(f"[Daemon] Error handling client: {e}")
        finally:
            print("[Daemon] Client disconnected.")
            conn.close()

if __name__ == '__main__':
    daemon = MobileActivationDaemon()
    daemon.start()
