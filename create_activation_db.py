import sqlite3
import os

DB_FILE = 'activation.db'

def create_database():
    """
    Creates the activation database and the devices table if they don't already exist.
    """
    if os.path.exists(DB_FILE):
        print(f"Database file '{DB_FILE}' already exists.")
        return

    try:
        conn = sqlite3.connect(DB_FILE)
        cursor = conn.cursor()

        # Define the table schema
        create_table_query = """
        CREATE TABLE devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            udid TEXT NOT NULL UNIQUE,
            imei TEXT,
            serial_number TEXT NOT NULL UNIQUE,
            product_type TEXT,
            activation_state TEXT NOT NULL DEFAULT 'Unactivated',
            apple_id TEXT,
            password TEXT,
            activation_record BLOB,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        """

        cursor.execute(create_table_query)

        # Create a trigger to automatically update the updated_at timestamp
        create_trigger_query = """
        CREATE TRIGGER update_devices_updated_at
        AFTER UPDATE ON devices
        FOR EACH ROW
        BEGIN
            UPDATE devices SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
        END;
        """
        cursor.execute(create_trigger_query)

        conn.commit()
        print(f"Database '{DB_FILE}' created successfully with 'devices' table.")

    except sqlite3.Error as e:
        print(f"Database error: {e}")
    finally:
        if conn:
            conn.close()

if __name__ == '__main__':
    create_database()
