<?php
/**
 * Customer documents — files attached to a customer profile (ID scans,
 * certificates, signed waivers, etc.) with a lightweight pending →
 * verified/rejected workflow. `doc_type` is an opaque integrator-defined
 * key; the file itself is a regular WP attachment (file_id).
 *
 * @package Bookflow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bookflow_Customer_Documents {

    /**
     * @return int|WP_Error document id
     */
    public static function add($customer_id, $doc_type, $file_id, $uploaded_by = null) {
        global $wpdb;

        $inserted = $wpdb->insert($wpdb->prefix . 'bookflow_customer_documents', [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists
            'customer_id' => absint($customer_id),
            'doc_type'    => sanitize_key($doc_type),
            'file_id'     => absint($file_id),
            'status'      => 'pending',
            'uploaded_by' => $uploaded_by ? absint($uploaded_by) : (get_current_user_id() ?: null),
        ]);

        if (!$inserted) {
            return new WP_Error('db_error', 'Could not save document.');
        }

        $doc_id = (int) $wpdb->insert_id;
        do_action('bookflow_customer_document_added', $doc_id, $customer_id, $doc_type);

        return $doc_id;
    }

    public static function verify($document_id, $verified_by = null) {
        return self::set_status($document_id, 'verified', $verified_by);
    }

    public static function reject($document_id, $verified_by = null) {
        return self::set_status($document_id, 'rejected', $verified_by);
    }

    private static function set_status($document_id, $status, $verified_by = null) {
        global $wpdb;

        $updated = $wpdb->update($wpdb->prefix . 'bookflow_customer_documents', [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no core API exists
            'status'      => $status,
            'verified_by' => $verified_by ? absint($verified_by) : (get_current_user_id() ?: null),
            'verified_at' => current_time('mysql'),
        ], ['id' => absint($document_id)]);

        if ($updated !== false) {
            do_action('bookflow_customer_document_status_changed', $document_id, $status);
        }

        return $updated !== false;
    }

    public static function get_for_customer($customer_id, $doc_type = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'bookflow_customer_documents';

        if ($doc_type) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE customer_id = %d AND doc_type = %s ORDER BY created_at DESC",
                absint($customer_id),
                sanitize_key($doc_type)
            ));
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE customer_id = %d ORDER BY created_at DESC",
            absint($customer_id)
        ));
    }

    public static function has_verified($customer_id, $doc_type) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no core API exists
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bookflow_customer_documents
             WHERE customer_id = %d AND doc_type = %s AND status = 'verified'",
            absint($customer_id),
            sanitize_key($doc_type)
        ));
        return (int) $count > 0;
    }
}
