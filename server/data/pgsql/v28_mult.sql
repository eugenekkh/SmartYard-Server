CREATE TABLE houses_subscribers_devices
(
    subscriber_device_id SERIAL PRIMARY KEY,
    house_subscriber_id INTEGER,
    device_token CHARACTER VARYING,
    auth_token CHARACTER VARYING,
    platform INTEGER,                                                                                                   -- 0 - android, 1 - ios, 2 - web
    push_token CHARACTER VARYING,
    push_token_type INTEGER,                                                                                            -- 0 - fcm, 1 - apple, 2 - apple (dev), 4 - huawei, 5 - rustore
    voip_token CHARACTER VARYING,                                                                                       -- iOS only
    registered INTEGER,                                                                                                 -- UNIX timestamp
    last_seen INTEGER,                                                                                                  -- UNIX timestamp
    voip_enabled INTEGER
);
CREATE UNIQUE INDEX houses_subscribers_devices_uniq_1 on houses_subscribers_devices(device_token);
CREATE UNIQUE INDEX houses_subscribers_devices_uniq_2 on houses_subscribers_devices(auth_token);
CREATE UNIQUE INDEX houses_subscribers_devices_uniq_3 on houses_subscribers_devices(push_token);
CREATE INDEX houses_subscribers_devices_house_subscriber_id on houses_subscribers_devices(house_subscriber_id);

-- cameras <-> entrances
CREATE TABLE houses_entrances_cameras
(
    camera_id INTEGER NOT NULL,
    house_entrance_id INTEGER NOT NULL,
    path CHARACTER VARYING,
    distance INTEGER                                                                                                    -- "distance" (order) (virtual) from camera to domophone
);
CREATE UNIQUE INDEX houses_entrances_cameras_uniq on houses_entrances_cameras(camera_id, house_entrance_id);
CREATE INDEX houses_entrances_cameras_camera_id on houses_entrances_cameras(camera_id);
CREATE INDEX houses_entrances_cameras_house_entrance_id on houses_entrances_cameras(house_entrance_id);
