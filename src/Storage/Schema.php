<?php

declare(strict_types=1);

namespace ArchitectureDecisionLab\Storage;

use ArchitectureDecisionLab\Domain\ResourceType;

/**
 * Explicit schema creation. There is no migration framework: the lab creates
 * fresh databases for every run, and the SQL is part of what the chapter
 * explains.
 */
final class Schema
{
    public static function inventory(SqliteDatabase $db, ResourceType $type): void
    {
        $table = $type->table();

        $db->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id TEXT PRIMARY KEY,
                available INTEGER NOT NULL,
                price_cents INTEGER NOT NULL
            )',
            $table,
        ));

        $db->execute(
            'CREATE TABLE IF NOT EXISTS reservations (
                booking_id TEXT NOT NULL,
                resource_type TEXT NOT NULL,
                resource_id TEXT NOT NULL,
                units INTEGER NOT NULL,
                status TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY (booking_id, resource_type, resource_id)
            )',
        );

        $db->execute(
            'CREATE INDEX IF NOT EXISTS reservations_resource
             ON reservations (resource_type, resource_id)',
        );

        $db->execute(
            'CREATE TABLE IF NOT EXISTS documents (
                collection TEXT NOT NULL,
                id TEXT NOT NULL,
                body TEXT NOT NULL,
                version INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY (collection, id)
            )',
        );
    }

    public static function payment(SqliteDatabase $db): void
    {
        $db->execute(
            'CREATE TABLE IF NOT EXISTS payments (
                booking_id TEXT PRIMARY KEY,
                amount_cents INTEGER NOT NULL,
                status TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )',
        );
    }

    public static function booking(SqliteDatabase $db): void
    {
        $db->execute(
            'CREATE TABLE IF NOT EXISTS bookings (
                id TEXT PRIMARY KEY,
                traveler_id TEXT NOT NULL,
                flight_id TEXT NOT NULL,
                hotel_id TEXT NOT NULL,
                car_id TEXT NOT NULL,
                status TEXT NOT NULL,
                amount_cents INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )',
        );

        $db->execute(
            'CREATE INDEX IF NOT EXISTS bookings_traveler
             ON bookings (traveler_id, status)',
        );

        $db->execute(
            'CREATE TABLE IF NOT EXISTS booking_lines (
                booking_id TEXT NOT NULL,
                resource_type TEXT NOT NULL,
                resource_id TEXT NOT NULL,
                units INTEGER NOT NULL,
                price_cents INTEGER NOT NULL,
                state TEXT NOT NULL,
                PRIMARY KEY (booking_id, resource_type)
            )',
        );

        $db->execute(
            'CREATE TABLE IF NOT EXISTS outbox (
                sequence INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id TEXT NOT NULL UNIQUE,
                type TEXT NOT NULL,
                payload TEXT NOT NULL,
                status TEXT NOT NULL,
                attempts INTEGER NOT NULL,
                occurred_at INTEGER NOT NULL,
                published_at INTEGER
            )',
        );

        $db->execute(
            'CREATE INDEX IF NOT EXISTS outbox_pending
             ON outbox (status, sequence)',
        );

        $db->execute(
            'CREATE TABLE IF NOT EXISTS documents (
                collection TEXT NOT NULL,
                id TEXT NOT NULL,
                body TEXT NOT NULL,
                version INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY (collection, id)
            )',
        );
    }

    public static function reporting(SqliteDatabase $db): void
    {
        $db->execute(
            'CREATE TABLE IF NOT EXISTS processed_events (
                consumer TEXT NOT NULL,
                event_id TEXT NOT NULL,
                processed_at INTEGER NOT NULL,
                PRIMARY KEY (consumer, event_id)
            )',
        );

        $db->execute(
            'CREATE TABLE IF NOT EXISTS availability_view (
                resource_type TEXT NOT NULL,
                resource_id TEXT NOT NULL,
                available INTEGER NOT NULL,
                version INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY (resource_type, resource_id)
            )',
        );

        $db->execute(
            'CREATE TABLE IF NOT EXISTS trip_view (
                booking_id TEXT PRIMARY KEY,
                status TEXT NOT NULL,
                traveler_id TEXT NOT NULL,
                amount_cents INTEGER NOT NULL,
                version INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )',
        );
    }

    public static function all(SqliteDatabase $db): void
    {
        foreach (ResourceType::cases() as $type) {
            self::inventory($db, $type);
        }

        self::payment($db);
        self::booking($db);
        self::reporting($db);
    }
}
