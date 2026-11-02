<?php

/**
 * SQL expression that resolves a variant's display image with fallbacks:
 * 1) primary image_id on the variant
 * 2) gallery image on the same variant
 * 3) any image from another variant of the same product and color
 * 4) any image from another variant of the same product
 */
function user_sql_variant_image_expr(string $variantAlias = 'v'): string
{
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $variantAlias) ?: 'v';

    return 'COALESCE(
        (SELECT i_pri.image FROM images i_pri WHERE i_pri.image_id = ' . $alias . '.image_id LIMIT 1),
        (
            SELECT i_gal.image
            FROM images i_gal
            WHERE i_gal.variant_id = ' . $alias . '.variant_id
            ORDER BY i_gal.sort_order, i_gal.image_id
            LIMIT 1
        ),
        (
            SELECT i_col.image
            FROM variants v_col
            INNER JOIN images i_col ON i_col.variant_id = v_col.variant_id
            WHERE v_col.product_id = ' . $alias . '.product_id
              AND (
                (' . $alias . '.color IS NULL OR ' . $alias . '.color = "")
                  AND (v_col.color IS NULL OR v_col.color = "")
                OR v_col.color = ' . $alias . '.color
              )
            ORDER BY
              CASE WHEN v_col.variant_id = ' . $alias . '.variant_id THEN 0 ELSE 1 END,
              i_col.sort_order,
              i_col.image_id
            LIMIT 1
        ),
        (
            SELECT i_any.image
            FROM variants v_any
            INNER JOIN images i_any ON i_any.variant_id = v_any.variant_id
            WHERE v_any.product_id = ' . $alias . '.product_id
            ORDER BY i_any.sort_order, i_any.image_id
            LIMIT 1
        )
    )';
}
