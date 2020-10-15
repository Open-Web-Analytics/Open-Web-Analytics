<!DOCTYPE html >
<?php $cu = $this->getCurrentUser(); ?>
<html>

  <head>
    <title>default Open Web Analytics - <?php echo $page_title;?></title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include($this->getTemplatePath('base','head.tpl'));?>
  </head>

  <body class="<?php if ($cu->user->isOWAAdmin()) echo 'owaadmin'; ?>">
    <style>
      html {background-color: #F2F2F2;}
    </style>

    <div class="owa">
      <?php include($this->getTemplatePath('base', 'header.tpl'));?>

      <?php include($this->getTemplatePath('base', 'msgs.tpl'));?>

      <?php echo $body;?>

      <?php include($this->getTemplatePath('base', 'footer.php'));?>
    </div>

    <script>
      window.addEventListener('DOMContentLoaded', e => {
        const header = document.getElementById('owa_header');
        const headerNav = header.querySelector('.owa_navigation');
        const sideNav = document.querySelector('.owa_admin_nav');
        const sideNavContainer = sideNav.parentNode;
        const mobileMenu = document.createElement('div');
        const userGreeting = header.querySelector('.user-greeting');
        const headerLogo = header.querySelector('.owa_logo');
        mobileMenu.classList.add('owa-mobile-menu');
        document.body.classList.add('owa-mobile-menu-closed');
        const mobileMenuButton = document.createElement('a');
        mobileMenuButton.setAttribute('id', 'owa-mobile-menu-button');
        mobileMenuButton.innerText = 'Menu';
        mobileMenuButton.setAttribute('href', '');
        mobileMenuButton.addEventListener('click', e => { e.preventDefault(); document.body.classList.toggle('owa-mobile-menu-open');mobileMenu.classList.toggle('owa-mobile-menu-closed'); });
        var isMobile = false;
        const handleResize = () => {
          if (mobileQuery.matches) {
            // Need to create mobile menu.
            mobileMenu.appendChild(userGreeting);
            mobileMenu.appendChild(headerNav);
            mobileMenu.appendChild(sideNav);
            header.appendChild(mobileMenu);
            header.appendChild(mobileMenuButton);
          }
          else {
            // Need to destroy mobile menu.
            headerLogo.insertAdjacentElement('afterend', headerNav);
            headerNav.insertAdjacentElement('afterend', userGreeting);
            sideNavContainer.insertAdjacentElement('afterbegin', sideNav);
            mobileMenu.remove();
            mobileMenuButton.remove();
          }
        };
        /* this 835 is matched in owa.css for the owa_reportLeftNavColumn too */
        const mobileQuery = window.matchMedia('(max-width: 835px)');
        mobileQuery.addListener(handleResize);
        if (mobileQuery.matches) {
          handleResize();
        }
      })
    </script>


  </body>

</html>
