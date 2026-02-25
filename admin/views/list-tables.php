<?php
/**
 * Admin view: All Tables list.
 *
 * @var object[] $tables
 * @var int      $total
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap advt-wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e( '소셜 커넥트 테이블', 'advanced-wp-tables' ); ?></h1>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=advt-table-add' ) ); ?>" class="page-title-action">
        <?php esc_html_e( '새로 추가', 'advanced-wp-tables' ); ?>
    </a>
    <hr class="wp-header-end">

    <?php if ( empty( $tables ) ) : ?>
        <div class="advt-no-tables">
            <p><?php esc_html_e( '테이블이 없습니다. 첫 번째 테이블을 만들어 보세요.', 'advanced-wp-tables' ); ?></p>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="column-id"><?php esc_html_e( 'ID', 'advanced-wp-tables' ); ?></th>
                    <th scope="col" class="column-name"><?php esc_html_e( '이름', 'advanced-wp-tables' ); ?></th>
                    <th scope="col" class="column-shortcode"><?php esc_html_e( '쇼트코드', 'advanced-wp-tables' ); ?></th>
                    <th scope="col" class="column-author"><?php esc_html_e( '작성자', 'advanced-wp-tables' ); ?></th>
                    <th scope="col" class="column-date"><?php esc_html_e( '수정일', 'advanced-wp-tables' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $tables as $tbl ) : ?>
                    <tr>
                        <td class="column-id"><?php echo esc_html( $tbl->id ); ?></td>
                        <td class="column-name">
                            <strong>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=advt-table-edit&table_id=' . $tbl->id ) ); ?>">
                                    <?php echo esc_html( $tbl->name ); ?>
                                </a>
                            </strong>
                            <div class="row-actions">
                                <span class="edit">
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=advt-table-edit&table_id=' . $tbl->id ) ); ?>">
                                        <?php esc_html_e( '편집', 'advanced-wp-tables' ); ?>
                                    </a> |
                                </span>
                                <span class="copy">
                                    <a href="#" class="advt-copy-table" data-table-id="<?php echo esc_attr( $tbl->id ); ?>">
                                        <?php esc_html_e( '복사', 'advanced-wp-tables' ); ?>
                                    </a> |
                                </span>
                                <span class="delete">
                                    <a href="#" class="advt-delete-table" data-table-id="<?php echo esc_attr( $tbl->id ); ?>">
                                        <?php esc_html_e( '삭제', 'advanced-wp-tables' ); ?>
                                    </a>
                                </span>
                            </div>
                        </td>
                        <td class="column-shortcode">
                            <code>[adv_wp_table id="<?php echo esc_attr( $tbl->id ); ?>" /]</code>
                        </td>
                        <td class="column-author">
                            <?php
                            $author = get_userdata( (int) $tbl->author_id );
                            echo $author ? esc_html( $author->display_name ) : '—';
                            ?>
                        </td>
                        <td class="column-date"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $tbl->updated_at ) ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
