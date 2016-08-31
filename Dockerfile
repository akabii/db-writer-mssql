FROM quay.io/keboola/docker-base-php56:0.0.2
MAINTAINER Miroslav Cillik <miro@keboola.com>

RUN yum -y --enablerepo=epel,remi,remi-php56 install php-mssql

# MSSQL
ADD mssql/freetds.conf /etc/freetds.conf

WORKDIR /home

# Initialize
COPY . /home/
RUN composer install --no-interaction

RUN curl --location --silent --show-error --fail \
        https://github.com/Barzahlen/waitforservices/releases/download/v0.3/waitforservices \
        > /usr/local/bin/waitforservices && \
    chmod +x /usr/local/bin/waitforservices

ENTRYPOINT php run.php --data=/data