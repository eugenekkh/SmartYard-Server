ALTER TABLE houses_flats_devices DROP CONSTRAINT IF EXISTS houses_flats_devices_pkey;
ALTER TABLE houses_flats_devices DROP COLUMN IF EXISTS house_flat_device_id;
ALTER TABLE houses_flats_devices ADD COLUMN IF NOT EXISTS houses_flat_device_id SERIAL NOT NULL;
ALTER TABLE houses_flats_devices ADD CONSTRAINT houses_flats_devices_pkey PRIMARY KEY (houses_flat_device_id);
